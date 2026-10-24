<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

it('exposes the requested cleaning mode for each persisted worker room', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($customer);

    $policy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default', 'billing_mode' => 'actual_working_time',
        'rules' => [], 'is_active' => true, 'is_default' => true,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'billing_policy_id' => $policy->id,
        'property_type' => 'apartment',
        'property_details' => [
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => ['bedroom' => ['small' => 2]],
            'room_cleaning_modes' => [
                'bedroom.small.1' => 'deep',
                'bedroom.small.2' => 'regular',
            ],
        ],
    ]);

    foreach ([1, 2] as $index) {
        $booking->rooms()->create([
            'room_key' => "bedroom.small.{$index}",
            'room_type' => 'bedroom',
            'room_size' => 'small',
            'display_label' => "غرفة نوم {$index}",
            'weight' => 1,
            'planned_worker_slot' => 1,
        ]);
    }

    // The operational worker endpoint is not the customer's detail endpoint.
    $this->getJson('/api/v1/cleaning-bookings/'.$booking->id)->assertForbidden();

    Sanctum::actingAs($workerUser);
    $response = $this->getJson('/api/v1/cleaning-bookings/'.$booking->id);
    $response->assertOk()
        ->assertJsonPath('data.propertyDetails.cleaning_mode_label', 'حوسة وتعزيل')
        ->assertJsonPath('data.propertyDetails.deep_room_count', 1)
        ->assertJsonPath('data.propertyDetails.regular_room_count', 1);
    $rooms = collect($response->json('data.roomAssignments'))->keyBy('roomKey');
    expect($rooms->count())->toBe(2)
        ->and($rooms['bedroom.small.1']['cleaningMode'])->toBe('deep')
        ->and($rooms['bedroom.small.1']['cleaningModeLabel'])->toBe('تعزيل')
        ->and($rooms['bedroom.small.2']['cleaningMode'])->toBe('regular')
        ->and($rooms['bedroom.small.2']['cleaningModeLabel'])->toBe('حوسة');
});
