<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\User;
use App\Models\Worker;
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
            'default_max_negative_balance' => 0,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ], $overrides),
    );
}

function createCompletedCleaningAssignment(User $customer, Worker $worker): CleaningBooking
{
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Completed,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => '12:00',
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
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

it('returns previous workers from multi worker assignments only when they are dispatch eligible', function (): void {
    seedPreviousWorkerEligibilitySettings();

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $eligibleWorker = Worker::factory()->create(['trust_score' => 80]);
    $lowTrustWorker = Worker::factory()->create(['trust_score' => 10]);
    $suspendedWorker = Worker::factory()->create(['trust_score' => 80, 'is_suspended' => true]);
    $inactiveWorker = Worker::factory()->create(['trust_score' => 80, 'is_active' => false]);
    $inactiveUser = User::factory()->create(['is_active' => false]);
    $inactiveAccountWorker = Worker::factory()->create(['user_id' => $inactiveUser->id, 'trust_score' => 80]);

    createCompletedCleaningAssignment($customer, $eligibleWorker);
    createCompletedCleaningAssignment($customer, $lowTrustWorker);
    createCompletedCleaningAssignment($customer, $suspendedWorker);
    createCompletedCleaningAssignment($customer, $inactiveWorker);
    createCompletedCleaningAssignment($customer, $inactiveAccountWorker);

    $response = $this->getJson('/api/v1/user/cleaning/orders/previous-workers?propertyType=house');

    $response->assertOk();
    $workerIds = collect($response->json('workers'))->pluck('workerId')->all();

    expect($workerIds)->toContain($eligibleWorker->id)
        ->not->toContain($lowTrustWorker->id)
        ->not->toContain($suspendedWorker->id)
        ->not->toContain($inactiveWorker->id)
        ->not->toContain($inactiveAccountWorker->id);
});

it('applies optional schedule and neighborhood filters to previous workers', function (): void {
    seedPreviousWorkerEligibilitySettings();

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $neighborhood = \Modules\Cleaning\Models\CleaningNeighborhood::factory()->create();
    $dayKey = mb_strtolower(now()->format('l'));

    $availableWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => [
            $dayKey => ['available' => true, 'data' => [['09:00' => '18:00']]],
        ],
    ]);
    $availableWorker->zones()->create([
        'name' => 'Covered area',
        'is_active' => true,
        'neighborhood_id' => $neighborhood->id,
    ]);

    $unavailableWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => [
            $dayKey => ['available' => true, 'data' => [['06:00' => '08:00']]],
        ],
    ]);
    $unavailableWorker->zones()->create([
        'name' => 'Covered area 2',
        'is_active' => true,
        'neighborhood_id' => $neighborhood->id,
    ]);

    createCompletedCleaningAssignment($customer, $availableWorker);
    createCompletedCleaningAssignment($customer, $unavailableWorker);

    $query = http_build_query([
        'propertyType' => 'house',
        'scheduledDate' => now()->toDateString(),
        'scheduledTime' => '12:00',
        'neighborhoodId' => $neighborhood->id,
    ]);

    $response = $this->getJson('/api/v1/user/cleaning/orders/previous-workers?'.$query);

    $response->assertOk();
    $workerIds = collect($response->json('workers'))->pluck('workerId')->all();

    expect($workerIds)->toContain($availableWorker->id)
        ->not->toContain($unavailableWorker->id);
});
