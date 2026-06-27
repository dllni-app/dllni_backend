<?php

declare(strict_types=1);

use App\Enums\WorkerPreferredWorkType;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

function eventVisibilityBillingPolicy(): CleaningBillingPolicy
{
    return CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
}

it('returns pending event assistance bookings without neighborhood to event workers', function (): void {
    $workerUser = User::factory()->create(['email' => 'event-visibility-worker@example.com']);
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'preferred_work_type' => WorkerPreferredWorkType::Events,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = eventVisibilityBillingPolicy();
    $eventBooking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'event_assistance',
        'neighborhood_id' => null,
        'neighborhood_name' => null,
        'scheduled_date' => now()->addDay()->format('Y-m-d'),
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toContain($eventBooking->id);
    expect($response->json('data.0.propertyType'))->toBe('event_assistance');
    expect($response->json('data.0.type'))->toBe('events');
});

it('counts pending event assistance bookings without neighborhood on worker homepage', function (): void {
    $workerUser = User::factory()->create(['email' => 'event-homepage-worker@example.com']);
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'preferred_work_type' => WorkerPreferredWorkType::Events,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = eventVisibilityBillingPolicy();
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'event_assistance',
        'neighborhood_id' => null,
        'neighborhood_name' => null,
        'scheduled_date' => now()->addDay()->format('Y-m-d'),
    ]);

    $response = $this->getJson('/api/v1/cleaning/worker/homepage');

    $response->assertOk();
    expect($response->json('newOrdersCount'))->toBe(1);
});
