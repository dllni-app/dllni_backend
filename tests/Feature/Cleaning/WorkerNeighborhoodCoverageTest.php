<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;

function neighborhoodCoverageAllDayHours(): array
{
    return [
        'monday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        'tuesday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        'wednesday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        'thursday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        'friday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        'saturday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        'sunday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
    ];
}

function createCoveredWorker(CleaningNeighborhood $neighborhood, array $attributes = []): array
{
    $user = User::factory()->create();
    $worker = Worker::factory()->create(array_merge([
        'user_id' => $user->id,
        'gender' => 'male',
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 80,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
        'default_working_hours' => neighborhoodCoverageAllDayHours(),
    ], $attributes));

    $worker->zones()->create([
        'neighborhood_id' => $neighborhood->id,
        'name' => $neighborhood->name_ar,
        'is_active' => true,
    ]);

    return [$user, $worker];
}

it('updates worker work areas using neighborhood ids and returns neighborhood payload', function (): void {
    $neighborhoodA = CleaningNeighborhood::factory()->create(['name_ar' => 'Aziziyah', 'name_en' => 'Aziziyah']);
    $neighborhoodB = CleaningNeighborhood::factory()->create(['name_ar' => 'Jamiliyah', 'name_en' => 'Jamiliyah']);
    [$user, $worker] = createCoveredWorker($neighborhoodA);
    $worker->zones()->create([
        'name' => 'Legacy Zone',
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/cleaning/worker/account/work-areas', [
        'zones' => [
            ['neighborhoodId' => $neighborhoodA->id, 'isActive' => true],
            ['neighborhoodId' => $neighborhoodB->id, 'isActive' => false],
        ],
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'zones')
        ->assertJsonPath('zones.0.neighborhoodId', $neighborhoodA->id);

    $this->assertDatabaseHas('worker_zones', [
        'worker_id' => $worker->id,
        'neighborhood_id' => $neighborhoodA->id,
        'name' => 'Aziziyah',
        'is_active' => true,
    ]);
    $this->assertDatabaseHas('worker_zones', [
        'worker_id' => $worker->id,
        'neighborhood_id' => $neighborhoodB->id,
        'name' => 'Jamiliyah',
        'is_active' => false,
    ]);
    $this->assertDatabaseMissing('worker_zones', [
        'worker_id' => $worker->id,
        'name' => 'Legacy Zone',
    ]);
});

it('rejects inactive neighborhoods when updating worker work areas', function (): void {
    $inactiveNeighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => 'Inactive Zone',
        'is_active' => false,
    ]);
    [$user] = createCoveredWorker(CleaningNeighborhood::factory()->create(['name_ar' => 'Covered Zone']));

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/cleaning/worker/account/work-areas', [
        'zones' => [
            ['neighborhoodId' => $inactiveNeighborhood->id],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['zones.0.neighborhoodId']);
});

it('dispatches new orders regardless of worker neighborhood coverage', function (): void {
    Notification::fake();

    $neighborhoodA = CleaningNeighborhood::factory()->create(['name_ar' => 'Aziziyah']);
    $neighborhoodB = CleaningNeighborhood::factory()->create(['name_ar' => 'Jamiliyah']);
    [$userA] = createCoveredWorker($neighborhoodA);
    [$userB] = createCoveredWorker($neighborhoodB);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'neighborhood_id' => $neighborhoodA->id,
        'neighborhood_name' => $neighborhoodA->name_ar,
    ]);

    (new NotifyEligibleWorkersNewOrderJob($booking->id))->handle();

    Notification::assertSentTo($userA, NewOrderRequestNotification::class);
    Notification::assertSentTo($userB, NewOrderRequestNotification::class);
});

it('shows current worker pending bookings regardless of neighborhood coverage', function (): void {
    $neighborhoodA = CleaningNeighborhood::factory()->create(['name_ar' => 'Aziziyah']);
    $neighborhoodB = CleaningNeighborhood::factory()->create(['name_ar' => 'Jamiliyah']);
    [$user] = createCoveredWorker($neighborhoodA);

    Sanctum::actingAs($user);

    $insideAreaBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'neighborhood_id' => $neighborhoodA->id,
        'neighborhood_name' => $neighborhoodA->name_ar,
    ]);
    $outsideAreaBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'neighborhood_id' => $neighborhoodB->id,
        'neighborhood_name' => $neighborhoodB->name_ar,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk()
        ->assertJsonCount(2, 'data');

    $bookingIds = collect($response->json('data'))
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();

    expect($bookingIds)->toContain($insideAreaBooking->id);
    expect($bookingIds)->toContain($outsideAreaBooking->id);
});

it('blocks workers from accepting bookings outside their neighborhoods', function (): void {
    $neighborhoodA = CleaningNeighborhood::factory()->create(['name_ar' => 'Aziziyah']);
    $neighborhoodB = CleaningNeighborhood::factory()->create(['name_ar' => 'Jamiliyah']);
    [$user] = createCoveredWorker($neighborhoodA);

    Sanctum::actingAs($user);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'address_latitude' => 36.21,
        'address_longitude' => 37.16,
        'neighborhood_id' => $neighborhoodB->id,
        'neighborhood_name' => $neighborhoodB->name_ar,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['worker']);
});
