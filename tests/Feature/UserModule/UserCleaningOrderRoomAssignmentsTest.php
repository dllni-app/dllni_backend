<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
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

    $this->roomAssignmentBookingPayload = static function (): array {
        return [
            'propertyType' => 'apartment',
            'propertyDetails' => [
                'address' => 'Damascus - Kafar Souseh',
                'location_name' => 'Home',
                'rooms' => 4,
                'bedrooms' => 1,
                'bathrooms' => 1,
                'kitchens' => 1,
                'living_room_size' => 'medium',
                'room_size_breakdown' => [
                    'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                    'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                    'kitchen' => ['small' => 0, 'medium' => 1, 'large' => 0],
                    'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
                    'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
                ],
            ],
            'assignmentMode' => 'open_count',
            'numberOfWorkers' => 2,
            'scheduledDate' => now()->addDay()->format('Y-m-d'),
            'scheduledTime' => '10:00',
            'addressLatitude' => 33.515,
            'addressLongitude' => 36.29,
            'genderPreference' => 'any',
            'termsAccepted' => true,
        ];
    };
});

it('allows the customer to assign rooms to accepted workers while the booking is still pending', function (): void {
    $customer = User::factory()->create(['email' => 'room-assignment-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->roomAssignmentBookingPayload)());
    $create->assertCreated();

    $orderId = (int) $create->json('order.id');
    $roomIds = collect($create->json('order.roomAssignments'))->pluck('id')->values()->all();

    $workerUser = User::factory()->create(['email' => 'room-assignment-worker@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.52,
        'home_longitude' => 36.31,
    ]);

    Sanctum::actingAs($workerUser);

    $accept = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");
    $accept->assertOk();
    expect($accept->json('data.status'))->toBe('pending');
    expect($accept->json('data.workerAcceptance.accepted'))->toBe(1);
    expect($accept->json('data.workerAcceptance.remaining'))->toBe(1);

    Sanctum::actingAs($customer);

    $update = patchJson("/api/v1/user/cleaning/orders/{$orderId}/room-assignments", [
        'assignments' => [
            ['roomId' => $roomIds[0], 'workerId' => $worker->id],
            ['roomId' => $roomIds[1], 'workerId' => $worker->id],
            ['roomId' => $roomIds[2], 'workerId' => null],
            ['roomId' => $roomIds[3], 'workerId' => null],
        ],
    ]);

    $update->assertOk();
    expect($update->json('order.status'))->toBe('pending');
    expect($update->json('order.workerId'))->toBeNull();
    expect($update->json('order.workerAcceptance.accepted'))->toBe(1);
    expect($update->json('order.workerAcceptance.remaining'))->toBe(1);

    $roomAssignments = collect($update->json('order.roomAssignments'))->keyBy('id');

    expect($roomAssignments->get($roomIds[0])['assignedWorkerId'])->toBe($worker->id);
    expect($roomAssignments->get($roomIds[1])['assignedWorkerId'])->toBe($worker->id);
    expect($roomAssignments->get($roomIds[2])['assignedWorkerId'])->toBeNull();
    expect($roomAssignments->get($roomIds[3])['assignedWorkerId'])->toBeNull();
});
