<?php

declare(strict_types=1);

use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('scopes supervised team worker details and never reveals cleaning materials or order pricing', function () {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team',
        'status' => CleaningBookingStatus::WorkerAssigned,
        'property_details' => [
            'room_cleaning_modes' => ['bedroom.medium.1' => 'deep'],
            'cleaning_materials' => ['secret-kit'],
        ],
    ]);
    $booking->rooms()->create([
        'room_key' => 'bedroom.medium.1',
        'room_type' => 'bedroom',
        'room_size' => 'medium',
        'display_label' => 'غرفة النوم',
        'weight' => 1,
    ]);
    $member = Worker::factory()->create();
    $outsider = Worker::factory()->create();
    $booking->workerAssignments()->create([
        'worker_id' => $member->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);

    Sanctum::actingAs($outsider->user);
    $this->getJson('/api/v1/cleaning/supervised-worker/bookings/'.$booking->id)
        ->assertNotFound();
    $this->getJson('/api/v1/cleaning-bookings')->assertJsonMissing(['id' => $booking->id]);

    Sanctum::actingAs($member->user);
    $result = $this->getJson('/api/v1/cleaning/supervised-worker/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('data.serviceType', 'supervised_team')
        ->assertJsonPath('data.scopeRooms.0.cleaningMode', 'deep')
        ->assertJsonPath('data.scopeRooms.0.title', 'غرفة النوم')
        ->assertJsonPath('data.allowedActions', ['view', 'sos']);

    expect($result->json('data'))->not->toHaveKeys(['materials', 'totalPrice', 'adminMargin']);
    expect(json_encode($result->json()))->not->toContain('secret-kit');

    $booking->workerAssignments()->where('worker_id', $member->id)
        ->update(['status' => 'withdrawn']);
    $this->getJson('/api/v1/cleaning/supervised-worker/bookings/'.$booking->id)
        ->assertNotFound();
});

it('refuses supervisor-owned worker execution and room claims at the API', function () {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team',
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $worker = Worker::factory()->create();
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    Sanctum::actingAs($worker->user);

    foreach (['start-work', 'complete', 'rooms/claim'] as $path) {
        $this->postJson('/api/v1/cleaning-bookings/'.$booking->id.'/'.$path, [])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUPERVISED_TEAM_ACTION_FORBIDDEN');
    }
    $this->getJson('/api/v1/cleaning-bookings/'.$booking->id.'/security-code')
        ->assertForbidden();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'started_travel_at' => null,
        'arrived_at' => null,
        'work_started_at' => null,
    ]);
});

it('does not confuse legacy open count bookings with supervisor managed service', function () {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $member = Worker::factory()->create();
    $booking->workerAssignments()->create([
        'worker_id' => $member->id,
        'status' => 'accepted',
    ]);
    Sanctum::actingAs($member->user);
    $this->getJson('/api/v1/cleaning/supervised-worker/bookings/'.$booking->id)
        ->assertNotFound();
});
