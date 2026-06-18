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
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\DepositService;
use Modules\User\Services\UserCleaningOrderService;

function seedDepositSettings(array $overrides = []): CleaningDepositSetting
{
    return CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        array_merge([
            'minimum_deposit_amount' => 1000,
            'default_max_negative_balance' => 200,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ], $overrides),
    );
}

function seedWorkerDeposit(Worker $worker, float $balance, ?float $minimumRequired = null, ?float $maxNegativeBalance = null): CleaningWorkerDeposit
{
    $settings = CleaningDepositSetting::query()->firstOrCreate([], [
        'minimum_deposit_amount' => 1000,
        'default_max_negative_balance' => 200,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 50,
    ]);

    return CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => $balance,
            'deposited_total' => max($balance, 0),
            'withdrawn_total' => 0,
            'minimum_required' => $minimumRequired ?? $settings->minimum_deposit_amount,
            'max_negative_balance' => $maxNegativeBalance ?? $settings->default_max_negative_balance,
        ],
    );
}

it('records admin deposit and increases balance and deposited total', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);

    app(DepositService::class)->recordDeposit($worker, 500, 'REF-001');

    $deposit = $worker->fresh()->deposit;
    expect((float) $deposit->current_balance)->toBe(500.0);
    expect((float) $deposit->deposited_total)->toBe(500.0);
    expect((float) $deposit->withdrawn_total)->toBe(0.0);
});

it('records withdrawal and increases withdrawn total without treating it as admin fee', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 1000);

    app(DepositService::class)->recordWithdrawal($worker, 300, 'WD-001');

    $deposit = $worker->fresh()->deposit;
    expect((float) $deposit->current_balance)->toBe(700.0);
    expect((float) $deposit->withdrawn_total)->toBe(300.0);
});

it('records admin fee debit without changing withdrawn total', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 1000);
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
    ]);

    app(DepositService::class)->recordAdminFeeDebit($worker, $booking, 150);

    $deposit = $worker->fresh()->deposit;
    expect((float) $deposit->current_balance)->toBe(850.0);
    expect((float) $deposit->withdrawn_total)->toBe(0.0);
    $this->assertDatabaseHas('cleaning_deposit_transactions', [
        'worker_id' => $worker->id,
        'type' => 'admin_fee',
        'cleaning_booking_id' => $booking->id,
        'amount' => 150,
    ]);
});

it('marks worker ineligible when balance crosses below configured floor', function (): void {
    seedDepositSettings(['default_max_negative_balance' => 100]);
    $worker = Worker::factory()->create(['trust_score' => 80, 'security_deposit_status' => 'active']);
    seedWorkerDeposit($worker, 50);

    $service = app(DepositService::class);
    expect($service->isWorkerEligibleForNewRequests($worker))->toBeTrue();

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
    ]);
    $service->recordAdminFeeDebit($worker, $booking, 200);

    $worker->refresh()->load('deposit');
    expect($service->isWorkerEligibleForNewRequests($worker))->toBeFalse();
    expect($worker->security_deposit_status)->toBe('insufficient_balance');
    expect($service->calculateExceedance($worker))->toBe(50.0);
});

it('keeps concurrent balance mutations consistent', function (): void {
    seedDepositSettings(['default_max_negative_balance' => 1000]);
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 0);
    $service = app(DepositService::class);

    $service->recordDeposit($worker, 100, 'A');
    $service->recordDeposit($worker, 50, 'B');
    $service->recordWithdrawal($worker, 30, 'C');

    $transactions = CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)
        ->orderBy('id')
        ->get();

    expect($transactions)->toHaveCount(3);
    expect((float) $transactions[0]->balance_after)->toBe((float) $transactions[1]->balance_before);
    expect((float) $transactions[1]->balance_after)->toBe((float) $transactions[2]->balance_before);
    expect((float) $worker->fresh()->deposit->current_balance)->toBe(120.0);
});

it('exposes deposit status via worker API with max negative balance', function (): void {
    seedDepositSettings(['minimum_deposit_amount' => 1000, 'default_max_negative_balance' => 200]);
    $user = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $user->id, 'trust_score' => 80]);
    seedWorkerDeposit($worker, 900);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/cleaning/worker/account/deposit');

    $response->assertOk();
    expect((float) $response->json('maxNegativeBalance'))->toBe(200.0);
    expect((float) $response->json('minimumRequired'))->toBe(1000.0);
    expect((float) $response->json('currentBalance'))->toBe(900.0);
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

it('debits admin fee when customer confirms completion', function (): void {
    seedDepositSettings();
    $worker = Worker::factory()->create(['trust_score' => 80]);
    seedWorkerDeposit($worker, 5000);

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

    $this->assertDatabaseHas('cleaning_deposit_transactions', [
        'worker_id' => $worker->id,
        'type' => 'admin_fee',
        'cleaning_booking_id' => $booking->id,
        'amount' => 100,
    ]);
    expect((float) $worker->fresh()->deposit->current_balance)->toBe(4900.0);
});

it('excludes ineligible workers from dispatch notifications', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00'));
    Notification::fake();

    try {
        seedDepositSettings(['trust_minimum_for_dispatch' => 50, 'default_max_negative_balance' => 0, 'is_enabled' => true]);
        $bookingDate = Carbon::now()->toDateString();
        $dayKey = mb_strtolower(Carbon::now()->format('l'));

        $ineligibleUser = User::factory()->create(['email' => 'ineligible-deposit@example.com']);
        $ineligibleWorker = Worker::factory()->create([
            'user_id' => $ineligibleUser->id,
            'trust_score' => 80,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['09:00' => '18:00']]],
            ],
        ]);
        $ineligibleWorker->zones()->create(['name' => 'Zone X', 'is_active' => true]);
        seedWorkerDeposit($ineligibleWorker, -10, 0);
        $ineligibleWorker->deposit()->update(['max_negative_balance' => 0]);

        $eligibleUser = User::factory()->create(['email' => 'eligible-deposit@example.com']);
        $eligibleWorker = Worker::factory()->create([
            'user_id' => $eligibleUser->id,
            'trust_score' => 80,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['09:00' => '18:00']]],
            ],
        ]);
        $eligibleWorker->zones()->create(['name' => 'Zone Y', 'is_active' => true]);
        seedWorkerDeposit($eligibleWorker, 5000);

        $booking = CleaningBooking::factory()->create([
            'worker_id' => null,
            'status' => CleaningBookingStatus::Pending->value,
            'gender_preference' => 'any',
            'scheduled_date' => $bookingDate,
            'scheduled_time' => '15:00',
        ]);

        (new NotifyEligibleWorkersNewOrderJob($booking->id))->handle();

        Notification::assertSentTo($eligibleUser, NewOrderRequestNotification::class);
        Notification::assertNotSentTo($ineligibleUser, NewOrderRequestNotification::class);
    } finally {
        Carbon::setTestNow();
    }
});

it('blocks start travel when worker does not meet deposit requirements', function (): void {
    seedDepositSettings(['minimum_deposit_amount' => 5000]);
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id, 'trust_score' => 80]);
    seedWorkerDeposit($worker, 100);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel")
        ->assertUnprocessable();
});
