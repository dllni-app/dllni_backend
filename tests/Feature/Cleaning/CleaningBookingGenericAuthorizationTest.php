<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Spatie\Permission\Models\Role;

function genericBookingOpsUser(string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function genericBookingWorker(): Worker
{
    $user = User::factory()->create(['is_active' => true]);

    return Worker::factory()->financiallyEligible()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'is_suspended' => false,
        'preferred_work_type' => 'cleaning',
    ]);
}

function genericBillingPolicy(): CleaningBillingPolicy
{
    return CleaningBillingPolicy::query()->first() ?? CleaningBillingPolicy::query()->create([
        'name' => 'Generic booking authorization',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
}

it('denies generic booking list show and mutations to unrelated authenticated non-operations actors', function (): void {
    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => genericBillingPolicy()->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $unrelatedCustomer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($unrelatedCustomer);

    $this->getJson('/api/v1/cleaning-bookings')->assertForbidden();
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();
    $this->postJson('/api/v1/cleaning-bookings', [
        'customerId' => $unrelatedCustomer->id,
    ])->assertForbidden();
    $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'customerId' => $booking->customer_id,
    ])->assertForbidden();
    $this->deleteJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();

    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    Sanctum::actingAs($supervisor);
    $this->getJson('/api/v1/cleaning-bookings')->assertForbidden();
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();

    $worker = genericBookingWorker();
    Sanctum::actingAs($worker->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertNotFound();
    $this->deleteJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();
});

it('preserves redacted pending dispatch for the intended worker and denies guessed pending ids to outsiders', function (string $bookingKind): void {
    $worker = genericBookingWorker();
    $outsider = genericBookingWorker();
    $customer = User::factory()->create([
        'is_active' => true,
        'email' => 'private-customer@example.test',
        'phone' => '+963999111222',
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'billing_policy_id' => genericBillingPolicy()->id,
        'status' => CleaningBookingStatus::Pending,
        'booking_kind' => $bookingKind,
        'number_of_workers' => 1,
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'address_latitude' => 36.201,
        'address_longitude' => 37.161,
        'property_details' => [
            'address' => 'Private pending address',
            'rooms' => 3,
        ],
    ]);

    Sanctum::actingAs($outsider->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertNotFound();

    Sanctum::actingAs($worker->user);
    $list = $this->getJson('/api/v1/cleaning-bookings?filter[status]=pending')->assertOk();
    $row = collect($list->json('data'))->firstWhere('id', $booking->id);
    expect($row)->not->toBeNull()
        ->and($row['visits'])->toBe([])
        ->and($row)->not->toHaveKeys([
            'customer', 'address', 'addressLatitude', 'addressLongitude', 'propertyDetails',
            'bookingTotalPrice', 'bookingBasePrice', 'rooms', 'roomAssignments',
        ]);

    $detail = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
    expect($detail->json('data.id'))->toBe($booking->id)
        ->and($detail->json('data.visits'))->toBe([])
        ->and($detail->json('data'))->not->toHaveKeys([
            'customer', 'address', 'addressLatitude', 'addressLongitude', 'propertyDetails',
            'bookingTotalPrice', 'bookingBasePrice', 'rooms', 'roomAssignments',
        ])
        ->and($detail->getContent())->not->toContain('private-customer@example.test')
        ->and($detail->getContent())->not->toContain('+963999111222')
        ->and($detail->getContent())->not->toContain('Private pending address');
})->with(['standard', 'open_time']);

it('keeps an initialized V2 parent visible to a partial worker while exposing only the owned visit', function (): void {
    $worker = genericBookingWorker();
    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => genericBillingPolicy()->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'worker_id' => $worker->id,
        'base_price' => 100,
        'total_price' => 100,
        'is_pricing_final' => false,
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'property_details' => [
            'address' => 'Accepted visit work site',
            '_time_period_v2' => ['rooms' => [['clientKey' => 'room.1']], 'visits' => [['sequence' => 1], ['sequence' => 2]]],
            '_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0],
        ],
    ]);
    $ownedRoom = $booking->rooms()->create([
        'room_key' => 'owned-room-key',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Owned Bedroom',
        'weight' => 1,
        'assigned_worker_id' => $worker->id,
        'planned_worker_slot' => 1,
    ]);
    $siblingRoom = $booking->rooms()->create([
        'room_key' => 'sibling-secret-room-key',
        'room_type' => 'kitchen',
        'room_size' => 'large',
        'display_label' => 'Sibling Kitchen',
        'weight' => 1,
        'assigned_worker_id' => $worker->id,
        'planned_worker_slot' => 1,
    ]);
    $sessions = collect([1, 2])->map(fn (int $sequence): CleaningBookingSession => $booking->sessions()->create([
        'sequence' => $sequence,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays($sequence + 1)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'worker_assigned',
    ]));
    $sessions->first()->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
        'service_share_amount' => 0,
        'rooms_weight' => 0,
        'worker_finished_property_rooms' => [
            ['id' => $ownedRoom->id, 'roomKey' => 'owned-room-key', 'displayLabel' => 'Owned Bedroom'],
            ['id' => $siblingRoom->id, 'roomKey' => 'sibling-secret-room-key', 'displayLabel' => 'Sibling Kitchen'],
        ],
    ]);
    foreach ([[$sessions[0], $ownedRoom, 'owned'], [$sessions[1], $siblingRoom, 'sibling']] as [$session, $room, $key]) {
        $start = CarbonImmutable::parse(
            $session->scheduled_date->toDateString().' 09:00',
            'Asia/Damascus',
        )->utc();
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => $key.'.schedule',
            'visit_key' => $key.'.visit',
            'timezone' => 'Asia/Damascus',
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'required_workers' => 2,
            'work_minutes' => 240,
            'duration_minutes' => 120,
        ]);
    }
    Sanctum::actingAs($worker->user);

    $list = $this->getJson('/api/v1/cleaning-bookings?filter[status]=worker_assigned')->assertOk();
    $row = collect($list->json('data'))->firstWhere('id', $booking->id);
    expect($row)->not->toBeNull()
        ->and($row['visits'])->toHaveCount(1)
        ->and($row['visits'][0]['sequence'])->toBe(1)
        ->and($row['addressLatitude'])->toBe(33.5138)
        ->and($row['addressLongitude'])->toBe(36.2765)
        ->and($row['roomAssignments'])->toHaveCount(1)
        ->and($row['roomAssignments'][0]['id'])->toBe($ownedRoom->id)
        ->and($row['roomAssignments'][0]['roomKey'])->toBe('owned-room-key')
        ->and($row['roomAssignments'][0]['displayLabel'])->toBe('Owned Bedroom')
        ->and($row['roomAssignments'][0]['assignedWorkerId'])->toBe($worker->id)
        ->and($row['workerOffer']['roomIds'])->toBe([$ownedRoom->id])
        ->and($row['worker_offer']['roomIds'])->toBe([$ownedRoom->id])
        ->and($row['myAssignment']['roomIds'])->toBe([$ownedRoom->id])
        ->and($row['worker_assignment']['roomIds'])->toBe([$ownedRoom->id])
        ->and(collect($row['myAssignment']['workerFinishedPropertyRooms'])->pluck('id')->all())->toBe([$ownedRoom->id])
        ->and(collect($row['worker_assignment']['worker_finished_property_rooms'])->pluck('id')->all())->toBe([$ownedRoom->id])
        ->and($row)->not->toHaveKeys(['customer', 'propertyDetails', 'rooms', 'bookingTotalPrice']);

    $detail = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
    expect($detail->json('data.visits'))->toHaveCount(1)
        ->and($detail->json('data.visits.0.sequence'))->toBe(1)
        ->and($detail->json('data.addressLatitude'))->toBe(33.5138)
        ->and($detail->json('data.addressLongitude'))->toBe(36.2765)
        ->and($detail->json('data.roomAssignments'))->toHaveCount(1)
        ->and($detail->json('data.roomAssignments.0.id'))->toBe($ownedRoom->id)
        ->and($detail->json('data.roomAssignments.0.roomKey'))->toBe('owned-room-key')
        ->and($detail->json('data.workerOffer.roomIds'))->toBe([$ownedRoom->id])
        ->and($detail->json('data.worker_offer.roomIds'))->toBe([$ownedRoom->id])
        ->and($detail->json('data.myAssignment.roomIds'))->toBe([$ownedRoom->id])
        ->and($detail->json('data.worker_assignment.roomIds'))->toBe([$ownedRoom->id])
        ->and(collect($detail->json('data.myAssignment.workerFinishedPropertyRooms'))->pluck('id')->all())->toBe([$ownedRoom->id])
        ->and(collect($detail->json('data.worker_assignment.worker_finished_property_rooms'))->pluck('id')->all())->toBe([$ownedRoom->id])
        ->and($detail->json('data'))->not->toHaveKeys(['customer', 'propertyDetails', 'rooms', 'bookingTotalPrice'])
        ->and($detail->getContent())->not->toContain('sibling-secret-room-key')
        ->and($detail->getContent())->not->toContain('Sibling Kitchen');
});

it('fails closed for signed metadata-only multiworker V2 bookings without accepted visit proof', function (string $bookingKind): void {
    $worker = genericBookingWorker();
    $customer = User::factory()->create([
        'is_active' => true,
        'email' => 'metadata-only-private@example.test',
        'phone' => '+963955000111',
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'billing_policy_id' => genericBillingPolicy()->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'booking_kind' => $bookingKind,
        'number_of_workers' => 2,
        'worker_id' => $worker->id,
        'property_details' => [
            'address' => 'Metadata-only private site',
            '_time_period_v2' => [
                'rooms' => [['clientKey' => 'metadata-only-room']],
                'visits' => [['sequence' => 1]],
            ],
            '_room_period_v2_approved_total' => 100,
        ],
    ]);
    Sanctum::actingAs($worker->user);

    $list = $this->getJson('/api/v1/cleaning-bookings?filter[status]=worker_assigned')->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->not->toContain($booking->id)
        ->and($list->getContent())->not->toContain('metadata-only-private@example.test')
        ->and($list->getContent())->not->toContain('+963955000111')
        ->and($list->getContent())->not->toContain('Metadata-only private site');

    $detail = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertNotFound();
    expect($detail->getContent())->not->toContain('metadata-only-private@example.test')
        ->and($detail->getContent())->not->toContain('+963955000111')
        ->and($detail->getContent())->not->toContain('Metadata-only private site');
})->with(['standard', 'open_time']);

it('scrubs nested room metadata for pending V2 dispatch before any visit is accepted', function (): void {
    $worker = genericBookingWorker();
    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => genericBillingPolicy()->id,
        'status' => CleaningBookingStatus::Pending,
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'base_price' => 100,
        'total_price' => 100,
        'is_pricing_final' => false,
        'property_details' => [
            'rooms' => 2,
            '_time_period_v2' => [
                'rooms' => [['clientKey' => 'pending-a'], ['clientKey' => 'pending-b']],
                'visits' => [['sequence' => 1], ['sequence' => 2]],
            ],
            '_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0],
        ],
    ]);
    $firstRoom = $booking->rooms()->create([
        'room_key' => 'pending-a',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Pending A',
        'weight' => 1,
        'planned_worker_slot' => 1,
    ]);
    $secondRoom = $booking->rooms()->create([
        'room_key' => 'pending-b',
        'room_type' => 'kitchen',
        'room_size' => 'large',
        'display_label' => 'Pending B Secret',
        'weight' => 1,
        'planned_worker_slot' => 1,
    ]);
    Sanctum::actingAs($worker->user);

    $list = $this->getJson('/api/v1/cleaning-bookings?filter[status]=pending')->assertOk();
    $row = collect($list->json('data'))->firstWhere('id', $booking->id);
    expect($row)->not->toBeNull()
        ->and($row['visits'])->toBe([])
        ->and($row['workerOffer']['roomIds'])->toBe([])
        ->and($row['worker_offer']['roomIds'])->toBe([])
        ->and($row['myAssignment']['roomIds'])->toBe([])
        ->and($row['worker_assignment']['roomIds'])->toBe([])
        ->and($row)->not->toHaveKey('roomAssignments')
        ->and(json_encode($row))->not->toContain('Pending B Secret')
        ->and(json_encode($row))->not->toContain('pending-b');

    $detail = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
    expect($detail->json('data.visits'))->toBe([])
        ->and($detail->json('data.workerOffer.roomIds'))->toBe([])
        ->and($detail->json('data.worker_offer.roomIds'))->toBe([])
        ->and($detail->json('data.myAssignment.roomIds'))->toBe([])
        ->and($detail->json('data.worker_assignment.roomIds'))->toBe([])
        ->and($detail->json('data'))->not->toHaveKey('roomAssignments')
        ->and($detail->getContent())->not->toContain('Pending B Secret')
        ->and($detail->getContent())->not->toContain('pending-b');

    expect($firstRoom->id)->not->toBe($secondRoom->id);
});

it('keeps uninitialized and mixed V2 groups scoped to each workers accepted visits', function (): void {
    $firstWorker = genericBookingWorker();
    $secondWorker = genericBookingWorker();
    $outsider = genericBookingWorker();
    $customer = User::factory()->create([
        'is_active' => true,
        'email' => 'mixed-v2-private@example.test',
        'phone' => '+963944555666',
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'billing_policy_id' => genericBillingPolicy()->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'worker_id' => $firstWorker->id,
        'address_latitude' => 33.5101,
        'address_longitude' => 36.2911,
        'property_details' => [
            'address' => 'Mixed V2 private work site',
            'rooms' => 2,
            '_time_period_v2' => [
                'rooms' => [['clientKey' => 'mixed-owned-room'], ['clientKey' => 'mixed-sibling-secret-room']],
                'visits' => [['sequence' => 1], ['sequence' => 2]],
            ],
            '_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0],
        ],
    ]);
    $firstRoom = $booking->rooms()->create([
        'room_key' => 'mixed-owned-room',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'First worker room',
        'weight' => 1,
        'assigned_worker_id' => $firstWorker->id,
    ]);
    $secondRoom = $booking->rooms()->create([
        'room_key' => 'mixed-sibling-secret-room',
        'room_type' => 'kitchen',
        'room_size' => 'large',
        'display_label' => 'Second worker secret room',
        'weight' => 1,
        'assigned_worker_id' => $secondWorker->id,
    ]);
    $sessions = collect([1, 2])->map(function (int $sequence) use ($booking): CleaningBookingSession {
        return $booking->sessions()->create([
            'sequence' => $sequence,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => now(config('app.timezone'))->addDays($sequence + 2)->toDateString(),
            'scheduled_time' => $sequence === 1 ? '09:00' : '13:00',
            'duration_hours' => 2,
            'required_workers' => 2,
            'supervision_mode' => $sequence === 2 ? CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR : null,
            'supervision_initialized_at' => $sequence === 2 ? now() : null,
            'coverage_status' => 'searching',
            'status' => 'worker_assigned',
        ]);
    });
    $sessions[0]->workerAssignments()->create([
        'worker_id' => $firstWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    $sessions[1]->workerAssignments()->create([
        'worker_id' => $secondWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);

    foreach ([[$sessions[0], $firstRoom, '09:00'], [$sessions[1], $secondRoom, '13:00']] as [$session, $room, $time]) {
        $start = CarbonImmutable::parse($session->scheduled_date->toDateString().' '.$time, 'Asia/Damascus')->utc();
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => 'mixed.'.$session->sequence.'.schedule',
            'visit_key' => 'mixed.'.$session->sequence.'.visit',
            'timezone' => 'Asia/Damascus',
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'required_workers' => 2,
            'work_minutes' => 240,
            'duration_minutes' => 120,
        ]);
    }

    Sanctum::actingAs($firstWorker->user);
    $first = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
    expect($first->json('data.visits'))->toHaveCount(1)
        ->and($first->json('data.visits.0.sequence'))->toBe(1)
        ->and($first->json('data.addressLatitude'))->toBe(33.5101)
        ->and($first->json('data.addressLongitude'))->toBe(36.2911)
        ->and($first->json('data.roomAssignments'))->toHaveCount(1)
        ->and($first->json('data.roomAssignments.0.id'))->toBe($firstRoom->id)
        ->and($first->getContent())->not->toContain('mixed-sibling-secret-room')
        ->and($first->getContent())->not->toContain('Second worker secret room')
        ->and($first->getContent())->not->toContain('mixed-v2-private@example.test')
        ->and($first->getContent())->not->toContain('+963944555666');

    Sanctum::actingAs($secondWorker->user);
    $second = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
    expect($second->json('data.visits'))->toHaveCount(1)
        ->and($second->json('data.visits.0.sequence'))->toBe(2)
        ->and($second->json('data.roomAssignments'))->toHaveCount(1)
        ->and($second->json('data.roomAssignments.0.id'))->toBe($secondRoom->id)
        ->and($second->getContent())->not->toContain('mixed-owned-room')
        ->and($second->getContent())->not->toContain('First worker room');

    Sanctum::actingAs($outsider->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertNotFound();
});

it('allows privileged operations access for legacy pending bookings but keeps signed V2 and lifecycle state immutable', function (): void {
    $admin = genericBookingOpsUser();
    Sanctum::actingAs($admin);
    $policy = genericBillingPolicy();
    $customer = User::factory()->create(['is_active' => true]);

    $this->getJson('/api/v1/cleaning-bookings')->assertOk();
    $created = $this->postJson('/api/v1/cleaning-bookings', [
        'customerId' => $customer->id,
        'billingPolicyId' => $policy->id,
        'bookingNumber' => 'SEC-'.strtoupper(fake()->bothify('????##')),
        'propertyType' => 'apartment',
        'scheduledDate' => now()->addDays(3)->toDateString(),
        'scheduledTime' => '10:00',
        'numberOfWorkers' => 1,
        'termsAccepted' => true,
    ])->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $created->json('data.id'));

    $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'propertyDetails' => ['kitchens' => 2, 'cleaning_mode' => 'deep'],
        'termsAccepted' => true,
    ])->assertOk();
    expect(data_get($booking->fresh()->property_details, 'kitchens'))->toBe(2)
        ->and(data_get($booking->fresh()->property_details, 'cleaning_mode'))->toBe('deep');

    $beforePrice = (float) $booking->fresh()->total_price;
    $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'propertyDetails' => [
            '_time_period_v2' => ['visits' => [['sequence' => 999]]],
            '_supervisor_pricing' => ['hourlyRate' => 999999],
            '_room_period_v2_approved_total' => 1,
        ],
    ])->assertUnprocessable();
    $booking->refresh();
    expect(data_get($booking->property_details, '_time_period_v2'))->toBeNull()
        ->and(data_get($booking->property_details, '_supervisor_pricing'))->toBeNull()
        ->and((float) $booking->total_price)->toBe($beforePrice);

    $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'status' => CleaningBookingStatus::Completed->value,
        'basePrice' => 1,
        'totalPrice' => 1,
        'workStartedAt' => now()->subHour()->toIso8601String(),
        'workFinishedAt' => now()->toIso8601String(),
    ])->assertUnprocessable();
    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Pending)
        ->and((float) $booking->fresh()->total_price)->not->toBe(1.0);

    $deletable = CleaningBooking::factory()->create([
        'billing_policy_id' => $policy->id,
        'status' => CleaningBookingStatus::Pending,
        'number_of_workers' => 1,
    ]);
    $this->deleteJson("/api/v1/cleaning-bookings/{$deletable->id}")->assertNoContent();

    $v2 = CleaningBooking::factory()->create([
        'billing_policy_id' => $policy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'booking_kind' => 'open_time',
        'number_of_workers' => 2,
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
    ]);
    $v2->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'time',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);

    $this->putJson("/api/v1/cleaning-bookings/{$v2->id}", [
        'status' => CleaningBookingStatus::Completed->value,
        'basePrice' => 1,
        'totalPrice' => 1,
    ])->assertUnprocessable();
    $this->deleteJson("/api/v1/cleaning-bookings/{$v2->id}")->assertStatus(409);
    expect($v2->fresh()->status)->toBe(CleaningBookingStatus::WorkerAssigned)
        ->and((float) $v2->fresh()->total_price)->not->toBe(1.0);
});
