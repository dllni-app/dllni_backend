<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;

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

    $this->multiWorkerBookingPayload = static function (): array {
        return [
            'propertyType' => 'apartment',
            'propertyDetails' => [
                'address' => 'Damascus - Mazzeh',
                'location_name' => 'Family home',
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
            'scheduledTime' => '09:00',
            'addressLatitude' => 33.5138,
            'addressLongitude' => 36.2765,
            'genderPreference' => 'any',
            'termsAccepted' => true,
        ];
    };
});

it('keeps the booking pending after the first worker accepts and finalizes when the team is fulfilled', function (): void {
    $customer = User::factory()->create(['email' => 'multi-worker-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->multiWorkerBookingPayload)());
    $create->assertCreated();

    $orderId = (int) $create->json('order.id');
    $roomIds = collect($create->json('order.roomAssignments'))->pluck('id')->values()->all();

    $worker1User = User::factory()->create(['email' => 'multi-worker-1@example.com']);
    $worker1 = Worker::factory()->create([
        'user_id' => $worker1User->id,
        'home_address' => 'Worker One Home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    Sanctum::actingAs($worker1User);

    $acceptOne = postJson("/api/v1/cleaning-bookings/{$orderId}/accept", [
        'roomIds' => [$roomIds[0]],
    ]);

    $acceptOne->assertOk();
    expect($acceptOne->json('data.status'))->toBe('pending');
    expect($acceptOne->json('data.workerAcceptance.accepted'))->toBe(1);
    expect($acceptOne->json('data.workerAcceptance.remaining'))->toBe(1);
    expect($acceptOne->json('data.myAssignment.roomIds'))->toEqualCanonicalizing([$roomIds[0]]);
    expect($acceptOne->json('data.worker_assignment.roomIds'))->toEqualCanonicalizing([$roomIds[0]]);

    $worker2User = User::factory()->create(['email' => 'multi-worker-2@example.com']);
    Worker::factory()->create([
        'user_id' => $worker2User->id,
        'home_address' => 'Worker Two Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.4,
    ]);

    Sanctum::actingAs($worker2User);

    $acceptTwo = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");

    $acceptTwo->assertOk();
    expect($acceptTwo->json('data.status'))->toBe('worker_assigned');
    expect((int) $acceptTwo->json('data.workerId'))->toBe($worker1->id);
    expect($acceptTwo->json('data.workerAcceptance.accepted'))->toBe(2);
    expect($acceptTwo->json('data.workerAcceptance.remaining'))->toBe(0);
    expect($acceptTwo->json('data.workerAcceptance.isFulfilled'))->toBeTrue();
    expect(collect($acceptTwo->json('data.roomAssignments'))->pluck('assignedWorkerId'))->not->toContain(null);
});

it('allows an accepted worker to claim rooms while the booking is still pending', function (): void {
    $customer = User::factory()->create(['email' => 'claim-worker-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->multiWorkerBookingPayload)());
    $create->assertCreated();

    $orderId = (int) $create->json('order.id');
    $roomIds = collect($create->json('order.roomAssignments'))->pluck('id')->values()->all();

    $workerUser = User::factory()->create(['email' => 'claim-worker@example.com']);
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Claim Worker Home',
        'home_latitude' => 33.55,
        'home_longitude' => 36.35,
    ]);

    Sanctum::actingAs($workerUser);

    $accept = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");

    $accept->assertOk();
    expect($accept->json('data.status'))->toBe('pending');
    expect($accept->json('data.workerAcceptance.accepted'))->toBe(1);

    $claim = postJson("/api/v1/cleaning-bookings/{$orderId}/rooms/claim", [
        'roomIds' => [$roomIds[1], $roomIds[2]],
    ]);

    $claim->assertOk();
    expect($claim->json('data.status'))->toBe('pending');
    expect($claim->json('data.workerAcceptance.accepted'))->toBe(1);
    expect($claim->json('data.workerAcceptance.remaining'))->toBe(1);
    expect($claim->json('data.myAssignment.roomIds'))->toEqualCanonicalizing([$roomIds[1], $roomIds[2]]);
    expect($claim->json('data.worker_assignment.roomIds'))->toEqualCanonicalizing([$roomIds[1], $roomIds[2]]);
    expect(collect($claim->json('data.roomAssignments'))
        ->whereIn('id', [$roomIds[1], $roomIds[2]])
        ->pluck('assignedWorkerId'))->not->toContain(null);
});

it('applies planned worker room slots to accepted workers as the team fills', function (): void {
    $customer = User::factory()->create(['email' => 'planned-room-customer@example.com']);
    Sanctum::actingAs($customer);

    $payload = ($this->multiWorkerBookingPayload)();
    $payload['workerRoomAssignments'] = [
        [
            'workerSlot' => 1,
            'preferredWorkerId' => null,
            'rooms' => [
                ['roomKey' => 'bedroom.small.1', 'roomType' => 'bedroom', 'roomSize' => 'small'],
                ['roomKey' => 'bathroom.small.1', 'roomType' => 'bathroom', 'roomSize' => 'small'],
            ],
        ],
        [
            'workerSlot' => 2,
            'preferredWorkerId' => null,
            'rooms' => [
                ['roomKey' => 'kitchen.medium.1', 'roomType' => 'kitchen', 'roomSize' => 'medium'],
            ],
        ],
    ];

    $create = postJson('/api/v1/user/cleaning/orders', $payload);
    $create->assertCreated();

    $orderId = (int) $create->json('order.id');

    $worker1User = User::factory()->create(['email' => 'planned-worker-1@example.com']);
    $worker1 = Worker::factory()->create([
        'user_id' => $worker1User->id,
        'home_address' => 'Planned Worker One Home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    Sanctum::actingAs($worker1User);

    $acceptOne = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");
    $acceptOne->assertOk();
    expect(collect($acceptOne->json('data.roomAssignments'))
        ->where('plannedWorkerSlot', 1)
        ->pluck('assignedWorkerId')
        ->unique()
        ->values()
        ->all())->toEqual([$worker1->id]);
    expect(collect($acceptOne->json('data.roomAssignments'))
        ->where('plannedWorkerSlot', 2)
        ->pluck('assignedWorkerId')
        ->filter()
        ->values()
        ->all())->toBeEmpty();

    $worker2User = User::factory()->create(['email' => 'planned-worker-2@example.com']);
    $worker2 = Worker::factory()->create([
        'user_id' => $worker2User->id,
        'home_address' => 'Planned Worker Two Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.4,
    ]);

    Sanctum::actingAs($worker2User);

    $acceptTwo = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");
    $acceptTwo->assertOk();
    expect(collect($acceptTwo->json('data.roomAssignments'))
        ->where('plannedWorkerSlot', 2)
        ->pluck('assignedWorkerId')
        ->unique()
        ->values()
        ->all())->toEqual([$worker2->id]);
});
