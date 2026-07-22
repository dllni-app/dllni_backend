<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\User\Services\UserCleaningOrderService;

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-20 08:00:00');

    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Test Cleaning Cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ]
    );

    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Test Cleaning Billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ]
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rejects preferred worker orders when the worker schedule overlaps an existing booking', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $neighborhood = CleaningNeighborhood::factory()->create(['name_ar' => 'Bustan al-Pasha']);
    $worker = Worker::factory()->create([
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $worker->zones()->create([
        'neighborhood_id' => $neighborhood->id,
        'name' => $neighborhood->name_ar,
        'is_active' => true,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => '2026-07-21',
        'scheduled_time' => '09:00',
        'total_hours' => 3,
        'estimated_hours' => 3,
        'gender_preference' => 'any',
    ]);

    $this->postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Aleppo - Bustan al-Pasha',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => '2026-07-21',
        'scheduledTime' => '10:00',
        'addressLatitude' => 36.22,
        'addressLongitude' => 37.16,
        'neighborhoodId' => $neighborhood->id,
        'preferredWorkerId' => $worker->id,
        'assignmentMode' => 'preferred_worker',
        'termsAccepted' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['preferredWorkerId'])
        ->assertJsonPath(
            'errors.preferredWorkerId.0',
            'مقدم الخدمة المختار غير متاح في الوقت المطلوب. يرجى اختيار وقت آخر أو عامل آخر.'
        );

    expect(app(UserCleaningOrderService::class))->not->toBeNull();
});
