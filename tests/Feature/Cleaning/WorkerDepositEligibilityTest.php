<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerTrustLog;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\DepositService;
use Modules\User\Services\UserCleaningOrderService;

function seedDepositSettings(array $overrides = []): CleaningDepositSetting
{
    $defaults = [
        'minimum_deposit_amount' => 0,
        'restriction_threshold_percent' => 100,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 50,
    ];

    return CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        array_merge($defaults, array_intersect_key($overrides, $defaults)),
    );
}

function seedWorkerDeposit(Worker $worker, float $depositBalance, ?float $allowedDebtLimit = null, float $debtBalance = 0): CleaningWorkerDeposit
{
    return CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => max(0, $depositBalance),
            'debt_balance' => max(0, $debtBalance),
            'deposited_total' => max($depositBalance, 0),
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => max(0, $allowedDebtLimit ?? 200),
        ],
    );
}

it('records admin deposit and increases the deposit balance and deposited total', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);

    app(DepositService::class)->recordDeposit($worker, 500, 'REF-001');

    $deposit = $worker->fresh()->deposit;
    expect((float) $deposit->current_balance)->toBe(500.0)
        ->and((float) $deposit->debt_balance)->toBe(0.0)
        ->and((float) $deposit->deposited_total)->toBe(500.0)
        ->and((float) $deposit->withdrawn_total)->toBe(0.0);
});

it('stores the legacy withdrawal operation as a refund limited to deposit', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 1000);

    $transaction = app(DepositService::class)->recordWithdrawal($worker, 300, 'WD-001');

    $deposit = $worker->fresh()->deposit;
    expect((float) $deposit->current_balance)->toBe(700.0)
        ->and((float) $deposit->debt_balance)->toBe(0.0)
        ->and((float) $deposit->withdrawn_total)->toBe(300.0)
        ->and($transaction->type)->toBe('refund');
});

it('records automatic commission and consumes deposit before debt', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 1000);
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
    ]);

    $transaction = app(DepositService::class)->recordAdminFeeDebit($worker, $booking, 150);
    $deposit = $worker->fresh()->deposit;

    expect((float) $deposit->current_balance)->toBe(850.0)
        ->and((float) $deposit->debt_balance)->toBe(0.0)
        ->and((float) $deposit->withdrawn_total)->toBe(0.0)
        ->and($transaction)->toBeInstanceOf(CleaningDepositTransaction::class)
        ->and($transaction?->type)->toBe('commission')
        ->and((float) $transaction?->amount)->toBe(150.0)
        ->and($transaction?->reference)->toStartWith(CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX)
        ->and(Schema::hasColumn('cleaning_deposit_transactions', 'cleaning_booking_id'))->toBeFalse();
});

it('marks worker ineligible when debt exceeds the worker-specific debt limit', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80, 'security_deposit_status' => 'active']);
    seedWorkerDeposit($worker, 50, 100);

    $service = app(DepositService::class);
    expect($service->isWorkerEligibleForNewRequests($worker))->toBeTrue();

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
    ]);
    $service->recordAdminFeeDebit($worker, $booking, 200);

    $worker->refresh()->load('deposit');
    expect((float) $worker->deposit->current_balance)->toBe(0.0)
        ->and((float) $worker->deposit->debt_balance)->toBe(150.0)
        ->and($service->isWorkerEligibleForNewRequests($worker))->toBeFalse()
        ->and($worker->security_deposit_status)->toBe('insufficient_balance')
        ->and($service->calculateExceedance($worker))->toBe(50.0);
});

it('keeps sequential deposit and refund balance mutations consistent', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 0, 1000);
    $service = app(DepositService::class);

    $service->recordDeposit($worker, 100, 'A');
    $service->recordDeposit($worker, 50, 'B');
    $service->recordWithdrawal($worker, 30, 'C');

    $transactions = CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)
        ->orderBy('id')
        ->get();

    expect($transactions)->toHaveCount(3)
        ->and((float) $transactions[0]->balance_after)->toBe((float) $transactions[1]->balance_before)
        ->and((float) $transactions[1]->balance_after)->toBe((float) $transactions[2]->balance_before)
        ->and((float) $worker->fresh()->deposit->current_balance)->toBe(120.0)
        ->and((float) $worker->fresh()->deposit->debt_balance)->toBe(0.0);
});

it('exposes explicit deposit debt and capacity values through the worker API', function (): void {
    seedDepositSettings(['minimum_deposit_amount' => 1000]);
    $user = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $user->id, 'trust_score' => 80]);
    seedWorkerDeposit($worker, 90, 200, 50);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/cleaning/worker/account/deposit');

    $response->assertOk()
        ->assertJsonPath('depositBalance', 90)
        ->assertJsonPath('currentBalance', 90)
        ->assertJsonPath('debtBalance', 50)
        ->assertJsonPath('minimumRequired', 0)
        ->assertJsonPath('allowedDebtLimit', 200)
        ->assertJsonPath('remainingDebtCapacity', 150)
        ->assertJsonPath('availableCommissionCapacity', 240);
});

it('does not apply trust penalty when rejecting before accept', function (): void {
    seedDepositSettings();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id, 'trust_score' => 90]);
    seedWorkerDeposit($worker, 5000);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
        'worker_id' => null,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", ['reason' => 'Busy'])
        ->assertOk();

    expect($worker->fresh()->trust_score)->toBe(90);
    expect(WorkerTrustLog::query()->where('worker_id', $worker->id)->count())->toBe(0);
});

it('applies trust penalty when rejecting after accept', function (): void {
    seedDepositSettings();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id, 'trust_score' => 90]);
    seedWorkerDeposit($worker, 5000);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned,
        'worker_id' => $worker->id,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", ['reason' => 'Emergency'])
        ->assertOk();

    expect($worker->fresh()->trust_score)->toBe(80);
    $this->assertDatabaseHas('worker_trust_logs', [
        'worker_id' => $worker->id,
        'cleaning_booking_id' => $booking->id,
        'reason' => 'booking_rejected_after_accept',
        'score_before' => 90,
        'score_after' => 80,
        'score_delta' => -10,
    ]);
});

it('charges commission when the customer confirms completion', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 50);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'customer_id' => User::factory()->create()->id,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::StartApproved->value,
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 900,
        'travel_fee' => 0,
        'admin_margin_amount' => 100,
        'worker_amount' => 900,
        'currency' => 'SYP',
    ]);

    app(UserCleaningOrderService::class)->confirmCompletion($booking);

    $transaction = CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)
        ->where('type', 'commission')
        ->where('reference', 'like', CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%')
        ->first();

    expect($transaction)->toBeInstanceOf(CleaningDepositTransaction::class)
        ->and((float) $transaction?->amount)->toBe(100.0)
        ->and($transaction?->reference)->toStartWith(CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX)
        ->and((float) $worker->fresh()->deposit->current_balance)->toBe(0.0)
        ->and((float) $worker->fresh()->deposit->debt_balance)->toBe(50.0);
});

it('excludes workers whose debt exceeds their individual limit from new-order notifications', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00'));
    Notification::fake();

    try {
        seedDepositSettings(['trust_minimum_for_dispatch' => 50]);
        $bookingDate = Carbon::now()->toDateString();
        $dayKey = mb_strtolower(Carbon::now()->format('l'));

        $ineligibleUser = User::factory()->create(['email' => 'ineligible-deposit@example.com']);
        $ineligibleWorker = Worker::factory()->create([
            'user_id' => $ineligibleUser->id,
            'trust_score' => 80,
            'home_address' => 'Aleppo',
            'home_latitude' => 36.2000,
            'home_longitude' => 37.1500,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['09:00' => '18:00']]],
            ],
        ]);
        $ineligibleWorker->zones()->create(['name' => 'Zone X', 'is_active' => true]);
        seedWorkerDeposit($ineligibleWorker, 0, 0, 1);

        $eligibleUser = User::factory()->create(['email' => 'eligible-deposit@example.com']);
        $eligibleWorker = Worker::factory()->create([
            'user_id' => $eligibleUser->id,
            'trust_score' => 80,
            'home_address' => 'Aleppo',
            'home_latitude' => 36.2000,
            'home_longitude' => 37.1500,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['09:00' => '18:00']]],
            ],
        ]);
        $eligibleWorker->zones()->create(['name' => 'Zone Y', 'is_active' => true]);
        seedWorkerDeposit($eligibleWorker, 5000000, 0);

        $booking = CleaningBooking::factory()->create([
            'worker_id' => null,
            'status' => CleaningBookingStatus::Pending->value,
            'gender_preference' => 'any',
            'scheduled_date' => $bookingDate,
            'scheduled_time' => '15:00',
            'address_latitude' => 36.2000,
            'address_longitude' => 37.1500,
        ]);

        (new NotifyEligibleWorkersNewOrderJob($booking->id))->handle();

        Notification::assertSentTo($eligibleUser, NewOrderRequestNotification::class);
        Notification::assertNotSentTo($ineligibleUser, NewOrderRequestNotification::class);
    } finally {
        Carbon::setTestNow();
    }
});

it('allows start travel without requiring a minimum deposit', function (): void {
    seedDepositSettings(['minimum_deposit_amount' => 5000]);
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id, 'trust_score' => 80]);
    seedWorkerDeposit($worker, 100, 0);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel")
        ->assertOk();

    expect($booking->fresh()->started_travel_at)->not->toBeNull();
});
