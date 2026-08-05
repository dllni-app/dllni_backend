<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

function seedPreviousWorkerEligibilitySettings(array $overrides = []): CleaningDepositSetting
{
    return CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        array_merge([
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 50000,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ], $overrides),
    );
}

function seedPreviousWorkerDeposit(Worker $worker, float $balance = 100000): void
{
    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => $balance,
            'debt_balance' => 0,
            'deposited_total' => $balance,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 50000,
            'is_active' => true,
        ],
    );
}

function createCompletedCleaningAssignment(User $customer, Worker $worker): CleaningBooking
{
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => 'open_count',
        'status' => CleaningBookingStatus::Completed,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => '12:00',
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
        'accepted_at' => now()->subHour(),
        'work_finished_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 100,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 100,
        'currency' => 'SYP',
    ]);

    return $booking;
}

it('returns previous workers from team assignments only when they remain dispatch eligible', function (): void {
    seedPreviousWorkerEligibilitySettings();

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $eligibleWorker = Worker::factory()->create(['trust_score' => 80]);
    $lowTrustWorker = Worker::factory()->create(['trust_score' => 10]);
    $suspendedWorker = Worker::factory()->create(['trust_score' => 80, 'is_suspended' => true]);
    $inactiveWorker = Worker::factory()->create(['trust_score' => 80, 'is_active' => false]);
    $inactiveUser = User::factory()->create(['is_active' => false]);
    $inactiveAccountWorker = Worker::factory()->create(['user_id' => $inactiveUser->id, 'trust_score' => 80]);

    foreach ([$eligibleWorker, $lowTrustWorker, $suspendedWorker, $inactiveWorker, $inactiveAccountWorker] as $worker) {
        seedPreviousWorkerDeposit($worker);
        createCompletedCleaningAssignment($customer, $worker);
    }

    $response = $this->getJson('/api/v1/user/cleaning/orders/previous-workers');

    $response->assertOk();
    $workerIds = collect($response->json('workers'))->pluck('workerId')->all();

    expect($workerIds)->toContain($eligibleWorker->id)
        ->not->toContain($lowTrustWorker->id)
        ->not->toContain($suspendedWorker->id)
        ->not->toContain($inactiveWorker->id)
        ->not->toContain($inactiveAccountWorker->id);
});

it('ignores property type and neighborhood parameters while applying the optional schedule filter', function (): void {
    seedPreviousWorkerEligibilitySettings();

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $dayKey = mb_strtolower(now()->format('l'));

    $availableWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => [
            $dayKey => ['available' => true, 'data' => [['09:00' => '18:00']]],
        ],
    ]);
    seedPreviousWorkerDeposit($availableWorker);

    $unavailableWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => [
            $dayKey => ['available' => true, 'data' => [['06:00' => '08:00']]],
        ],
    ]);
    seedPreviousWorkerDeposit($unavailableWorker);

    createCompletedCleaningAssignment($customer, $availableWorker);
    createCompletedCleaningAssignment($customer, $unavailableWorker);

    $query = http_build_query([
        'propertyType' => 'unsupported-property-type',
        'scheduledDate' => now()->toDateString(),
        'scheduledTime' => '12:00',
        'neighborhoodId' => 'not-a-neighborhood-id',
    ]);

    $response = $this->getJson('/api/v1/user/cleaning/orders/previous-workers?'.$query);

    $response->assertOk();
    $workerIds = collect($response->json('workers'))->pluck('workerId')->all();

    expect($workerIds)->toContain($availableWorker->id)
        ->not->toContain($unavailableWorker->id);
});

it('excludes a previous worker whose accepted booking overlaps the requested interval', function (): void {
    seedPreviousWorkerEligibilitySettings();

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $scheduledDate = now()->addDay();
    $dayKey = mb_strtolower($scheduledDate->format('l'));
    $workingHours = [
        $dayKey => ['available' => true, 'data' => [['08:00' => '18:00']]],
    ];

    $conflictingWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => $workingHours,
    ]);
    $availableWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => $workingHours,
    ]);

    foreach ([$conflictingWorker, $availableWorker] as $worker) {
        seedPreviousWorkerDeposit($worker);
        createCompletedCleaningAssignment($customer, $worker);
    }

    $acceptedBooking = CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => $scheduledDate->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 2,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $acceptedBooking->id,
        'worker_id' => $conflictingWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 100,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 100,
        'currency' => 'SYP',
    ]);

    $query = http_build_query([
        'scheduledDate' => $scheduledDate->toDateString(),
        'scheduledTime' => '11:00',
        'durationHours' => 1,
    ]);

    $response = $this->getJson('/api/v1/user/cleaning/orders/previous-workers?'.$query);

    $response->assertOk();
    $workerIds = collect($response->json('workers'))->pluck('workerId')->all();

    expect($workerIds)
        ->toContain($availableWorker->id)
        ->not->toContain($conflictingWorker->id);
});

it('rejects a preferred worker that can no longer receive new requests', function (): void {
    seedPreviousWorkerEligibilitySettings();

    $worker = Worker::factory()->create([
        'trust_score' => 80,
        'is_suspended' => true,
    ]);
    seedPreviousWorkerDeposit($worker);

    expect(fn () => CleaningBooking::factory()->create([
        'assignment_mode' => 'preferred_worker',
        'preferred_worker_id' => $worker->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
    ]))->toThrow(ValidationException::class, 'Selected worker cannot receive new cleaning requests.');
});
