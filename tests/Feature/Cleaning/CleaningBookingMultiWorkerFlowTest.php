<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;

use function Pest\Laravel\getJson;
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
    $roomTasks = collect($create->json('order.roomTasks'));
    expect($roomTasks)->not->toBeEmpty();

    $worker1User = User::factory()->create(['email' => 'multi-worker-1@example.com']);
    $worker1 = Worker::factory()->financiallyEligible()->create([
        'user_id' => $worker1User->id,
        'home_address' => 'Worker One Home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    Sanctum::actingAs($worker1User);

    $acceptOne = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");

    $acceptOne->assertOk();
    expect($acceptOne->json('data.status'))->toBe('pending');
    expect($acceptOne->json('data.order_status'))->toBe('pending');
    expect($acceptOne->json('data.worker_order_status'))->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value);
    expect($acceptOne->json('data.required_workers_count'))->toBe(2);
    expect($acceptOne->json('data.accepted_workers_count'))->toBe(1);
    expect($acceptOne->json('data.pending_workers_count'))->toBe(1);
    expect($acceptOne->json('data.workerAcceptance.accepted'))->toBe(1);
    expect($acceptOne->json('data.workerAcceptance.remaining'))->toBe(1);
    expect($acceptOne->json('data.myAssignment.status'))->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value);
    expect(collect($acceptOne->json('data.roomTasks'))->pluck('roomKey')->all())->toEqualCanonicalizing($roomTasks->pluck('roomKey')->all());
    $acceptOne->assertJsonMissingPath('data.myAssignment.roomIds')->assertJsonMissingPath('data.worker_assignment.roomIds')
        ->assertJsonMissingPath('data.roomAssignments')->assertJsonMissingPath('data.workerRoomAssignments')
        ->assertJsonMissingPath('data.roomTasks.0.assignedWorkerId');

    $worker2User = User::factory()->create(['email' => 'multi-worker-2@example.com']);
    Worker::factory()->financiallyEligible()->create([
        'user_id' => $worker2User->id,
        'home_address' => 'Worker Two Home',
        // Keep this fixture within the initial 10-km dispatch radius.
        'home_latitude' => 33.52,
        'home_longitude' => 36.28,
    ]);

    Sanctum::actingAs($worker2User);

    $acceptTwo = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");

    $acceptTwo->assertOk();
    expect($acceptTwo->json('data.status'))->toBe('worker_assigned');
    expect((int) $acceptTwo->json('data.workerId'))->toBe($worker1->id);
    expect($acceptTwo->json('data.workerAcceptance.accepted'))->toBe(2);
    expect($acceptTwo->json('data.workerAcceptance.remaining'))->toBe(0);
    expect($acceptTwo->json('data.workerAcceptance.isFulfilled'))->toBeTrue();
    expect($acceptTwo->json('data.accepted_workers_count'))->toBe(2);
    expect($acceptTwo->json('data.pending_workers_count'))->toBe(0);
    expect(collect($acceptTwo->json('data.roomTasks'))->pluck('roomKey')->all())->toEqualCanonicalizing($roomTasks->pluck('roomKey')->all());
    $acceptTwo->assertJsonMissingPath('data.myAssignment.roomIds')->assertJsonMissingPath('data.worker_assignment.roomIds')
        ->assertJsonMissingPath('data.roomAssignments')->assertJsonMissingPath('data.roomTasks.0.assignedWorkerId');
});

it('moves to in progress only after the customer verifies start and all accepted workers approve', function (): void {
    $customer = User::factory()->create(['email' => 'start-approval-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->multiWorkerBookingPayload)());
    $create->assertCreated();

    $orderId = (int) $create->json('order.id');

    $worker1User = User::factory()->create(['email' => 'start-approval-worker-1@example.com']);
    $worker1 = Worker::factory()->financiallyEligible()->create([
        'user_id' => $worker1User->id,
        'home_address' => 'Worker One Home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    Sanctum::actingAs($worker1User);
    postJson("/api/v1/cleaning-bookings/{$orderId}/accept")->assertOk();

    $worker2User = User::factory()->create(['email' => 'start-approval-worker-2@example.com']);
    Worker::factory()->financiallyEligible()->create([
        'user_id' => $worker2User->id,
        'home_address' => 'Worker Two Home',
        // Keep this fixture within the initial 10-km dispatch radius.
        'home_latitude' => 33.52,
        'home_longitude' => 36.28,
    ]);

    Sanctum::actingAs($worker2User);
    postJson("/api/v1/cleaning-bookings/{$orderId}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::WorkerAssigned->value);

    Sanctum::actingAs($worker1User);
    postJson("/api/v1/cleaning-bookings/{$orderId}/start-travel")->assertOk();
    postJson("/api/v1/cleaning-bookings/{$orderId}/arrive")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingStartVerification->value);
    $code = getJson("/api/v1/cleaning-bookings/{$orderId}/security-code")
        ->assertOk()
        ->json('data.securityCode');

    Sanctum::actingAs($customer);
    postJson("/api/v1/user/cleaning/orders/{$orderId}/start-verification/confirm", [
        'code' => $code,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value)
        ->assertJsonPath('data.start_approved_workers_count', 1)
        ->assertJsonPath('data.not_start_approved_workers_count', 1);

    Sanctum::actingAs($worker1User);
    postJson("/api/v1/cleaning-bookings/{$orderId}/start-work")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value)
        ->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value)
        ->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::InProgress->value)
        ->assertJsonPath('data.start_approved_workers_count', 1)
        ->assertJsonPath('data.not_start_approved_workers_count', 1);

    Sanctum::actingAs($worker2User);
    postJson("/api/v1/cleaning-bookings/{$orderId}/start-travel")->assertOk();
    postJson("/api/v1/cleaning-bookings/{$orderId}/arrive")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingStartVerification->value);
    $workerTwoCode = getJson("/api/v1/cleaning-bookings/{$orderId}/security-code")
        ->assertOk()
        ->json('data.securityCode');

    Sanctum::actingAs($customer);
    postJson("/api/v1/user/cleaning/orders/{$orderId}/start-verification/confirm", [
        'code' => $workerTwoCode,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value)
        ->assertJsonPath('data.start_approved_workers_count', 2)
        ->assertJsonPath('data.not_start_approved_workers_count', 0);

    Sanctum::actingAs($worker2User);
    postJson("/api/v1/cleaning-bookings/{$orderId}/start-work")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value)
        ->assertJsonPath('data.start_approved_workers_count', 2)
        ->assertJsonPath('data.not_start_approved_workers_count', 0);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $orderId,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $orderId,
        'worker_id' => $worker1->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
    ]);
});

it('shows all shared room requirements after acceptance and removes room claiming', function (): void {
    $customer = User::factory()->create(['email' => 'claim-worker-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->multiWorkerBookingPayload)());
    $create->assertCreated();

    $orderId = (int) $create->json('order.id');
    $roomTasks = collect($create->json('order.roomTasks'));
    expect($roomTasks)->not->toBeEmpty();

    $workerUser = User::factory()->create(['email' => 'claim-worker@example.com']);
    Worker::factory()->financiallyEligible()->create([
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

    expect(collect($accept->json('data.roomTasks'))->pluck('roomKey')->all())->toEqualCanonicalizing($roomTasks->pluck('roomKey')->all());
    $accept->assertJsonMissingPath('data.myAssignment.roomIds')->assertJsonMissingPath('data.worker_assignment.roomIds')
        ->assertJsonMissingPath('data.roomAssignments')->assertJsonMissingPath('data.roomTasks.0.assignedWorkerId');
    postJson("/api/v1/cleaning-bookings/{$orderId}/rooms/claim", ['roomIds' => [$roomTasks->first()['id']]])->assertNotFound();
});

it('rejects planned worker allocations and retains shared room tasks for the accepted team', function (): void {
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

    postJson('/api/v1/user/cleaning/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('workerRoomAssignments');
    unset($payload['workerRoomAssignments']);
    $create = postJson('/api/v1/user/cleaning/orders', $payload);
    $create->assertCreated();

    $orderId = (int) $create->json('order.id');

    $worker1User = User::factory()->create(['email' => 'planned-worker-1@example.com']);
    $worker1 = Worker::factory()->financiallyEligible()->create([
        'user_id' => $worker1User->id,
        'home_address' => 'Planned Worker One Home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
        'trust_score' => 90,
    ]);

    Sanctum::actingAs($worker1User);

    $acceptOne = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");
    $acceptOne->assertOk();
    $firstAssignments = collect($acceptOne->json('data.roomAssignments'));
    $roomKeys = collect($create->json('order.roomTasks'))->pluck('roomKey')->all();
    expect($roomKeys)->not->toBeEmpty();
    expect(collect($acceptOne->json('data.roomTasks'))->pluck('roomKey')->all())->toEqualCanonicalizing($roomKeys);
    $acceptOne->assertJsonMissingPath('data.roomAssignments')->assertJsonMissingPath('data.roomTasks.0.plannedWorkerSlot')
        ->assertJsonMissingPath('data.roomTasks.0.assignedWorkerId');

    $worker2User = User::factory()->create(['email' => 'planned-worker-2@example.com']);
    $worker2 = Worker::factory()->financiallyEligible()->create([
        'user_id' => $worker2User->id,
        'home_address' => 'Planned Worker Two Home',
        // Matching should be eligible without waiting for geographic expansion.
        'home_latitude' => 33.52,
        'home_longitude' => 36.28,
        'trust_score' => 80,
    ]);

    Sanctum::actingAs($worker2User);

    $acceptTwo = postJson("/api/v1/cleaning-bookings/{$orderId}/accept");
    $acceptTwo->assertOk();
    expect(collect($acceptTwo->json('data.roomTasks'))->pluck('roomKey')->all())->toEqualCanonicalizing($roomKeys);
    $acceptTwo->assertJsonMissingPath('data.roomAssignments')->assertJsonMissingPath('data.workerRoomAssignments')
        ->assertJsonMissingPath('data.roomTasks.0.plannedWorkerSlot')->assertJsonMissingPath('data.roomTasks.0.assignedWorkerId');
});
