<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

it('discloses customer destination only during the assigned worker travel window', function (): void {
    $customer = User::factory()->create([
        'name' => 'Private Customer Name',
        'email' => 'private-customer@example.test',
        'phone' => '+963900000001',
    ]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => 'worker_assigned',
        'address_latitude' => 33.5101,
        'address_longitude' => 36.2911,
        'property_details' => [
            'full_address' => 'Private Street 12',
            'neighborhood' => 'المزة',
            'address_notes' => 'Private access note',
            'building' => 'Private Building',
            'bedrooms' => 2,
        ],
    ]);
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    Sanctum::actingAs($workerUser);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.addressLatitude', null)
        ->assertJsonPath('data.address.fullAddress', null)
        ->assertJsonPath('data.address.neighborhoodName', 'المزة')
        ->assertJsonPath('data.customerId', null)
        ->assertJsonPath('data.customer.id', null)
        ->assertJsonPath('data.customer.name', null)
        ->assertJsonPath('data.customer.email', null)
        ->assertJsonPath('data.customer.phone', null)
        ->assertDontSee('Private Street 12')
        ->assertDontSee('Private Customer Name')
        ->assertDontSee('private-customer@example.test')
        ->assertDontSee('+963900000001')
        ->assertDontSee('Private access note')
        ->assertDontSee('Private Building');

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.addressLatitude', 33.5101)
        ->assertJsonPath('data.address.fullAddress', 'Private Street 12')
        ->assertJsonPath('data.address.notes', null)
        ->assertJsonPath('data.customer.phone', null)
        ->assertDontSee('Private Customer Name')
        ->assertDontSee('private-customer@example.test')
        ->assertDontSee('+963900000001');

    $assignment->forceFill(['arrived_at' => now()])->save();

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.addressLatitude', null)
        ->assertJsonPath('data.address.fullAddress', null)
        ->assertJsonPath('data.customer.phone', null);
});

it('redacts sibling worker identities and session ids from session acceptance coverage', function (): void {
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => User::factory()->create(['is_active' => true])->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $otherWorker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
    ]);
    $sessions = collect([1, 2])->map(fn (int $sequence): CleaningBookingSession => $booking->sessions()->create([
        'sequence' => $sequence,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays($sequence + 2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]));
    $sessions[1]->workerAssignments()->create([
        'worker_id' => $otherWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    $sessions[0]->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    Sanctum::actingAs($worker->user);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected", [
        'sessionIds' => [$sessions[0]->id],
    ])->assertOk();

    $coverage = collect($response->json('data.coverage'))->keyBy('sequence');
    expect($coverage[1]['sessionId'])->toBe($sessions[0]->id)
        ->and($coverage[1]['workerIds'])->toBe([$worker->id])
        ->and($coverage[2]['sessionId'])->toBeNull()
        ->and($coverage[2]['workerIds'])->toBe([]);
});

it('discloses a session destination only to its assigned worker after that worker starts travel', function (): void {
    $customer = User::factory()->create([
        'name' => 'Session Customer Private',
        'email' => 'session-private@example.test',
        'phone' => '+963900000003',
    ]);
    $worker = Worker::factory()->create(['user_id' => User::factory()->create(['is_active' => true])->id]);
    $sibling = Worker::factory()->create(['user_id' => User::factory()->create(['is_active' => true])->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'booking_kind' => 'open_time',
        'number_of_workers' => 2,
        'status' => 'worker_assigned',
        'address_latitude' => 33.5123,
        'address_longitude' => 36.2789,
        'neighborhood_name' => 'المزة',
        'property_details' => ['full_address' => 'Session Private Street 18'],
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => 'fully_covered',
        'status' => 'worker_assigned',
    ]);
    $session->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
        'started_travel_at' => now(),
    ]);
    $session->workerAssignments()->create([
        'worker_id' => $sibling->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);

    Sanctum::actingAs($worker->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}?sessionId={$session->id}")
        ->assertOk()
        ->assertJsonPath('data.addressLatitude', 33.5123)
        ->assertJsonPath('data.address.fullAddress', 'Session Private Street 18')
        ->assertJsonPath('data.customer.name', null)
        ->assertHeader('Cache-Control', 'no-store, private');

    Sanctum::actingAs($sibling->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}?sessionId={$session->id}")
        ->assertOk()
        ->assertJsonPath('data.addressLatitude', null)
        ->assertJsonPath('data.address.fullAddress', null)
        ->assertJsonPath('data.customer.name', null)
        ->assertDontSee('Session Private Street 18')
        ->assertDontSee('Session Customer Private')
        ->assertDontSee('session-private@example.test')
        ->assertDontSee('+963900000003');
});
