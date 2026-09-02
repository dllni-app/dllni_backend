<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

it('stores worker location on the active session assignment while travelling', function (): void {
    Event::fake([WorkerLocationUpdated::class]);
    [$workerUser, $worker, $booking, $session, $assignment] = makeSessionLocationScenario();

    $startedAt = now();
    $assignment->forceFill(['started_travel_at' => $startedAt])->save();
    $session->forceFill(['started_travel_at' => $startedAt])->save();

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/location",
        ['latitude' => 33.5138, 'longitude' => 36.2765],
    )
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.ignored', false)
        ->assertJsonPath('data.sessionId', $session->id);

    $fresh = $assignment->fresh();
    expect((float) $fresh->last_latitude)->toBe(33.5138)
        ->and((float) $fresh->last_longitude)->toBe(36.2765)
        ->and($fresh->location_updated_at)->not->toBeNull();
});

it('ignores session location before travel and after arrival', function (): void {
    Event::fake([WorkerLocationUpdated::class]);
    [$workerUser, , $booking, $session, $assignment] = makeSessionLocationScenario();

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/location",
        ['latitude' => 33.5, 'longitude' => 36.2],
    )
        ->assertOk()
        ->assertJsonPath('data.ignored', true);

    $assignment->forceFill([
        'started_travel_at' => now()->subMinutes(10),
        'arrived_at' => now(),
    ])->save();

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/location",
        ['latitude' => 33.6, 'longitude' => 36.3],
    )
        ->assertOk()
        ->assertJsonPath('data.ignored', true);

    expect($assignment->fresh()->location_updated_at)->toBeNull();
});

it('rejects session location from a worker who is not assigned to that session', function (): void {
    Event::fake([WorkerLocationUpdated::class]);
    [, , $booking, $session] = makeSessionLocationScenario();
    $otherUser = User::factory()->create(['is_active' => true]);
    Worker::factory()->create([
        'user_id' => $otherUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
    ]);

    Sanctum::actingAs($otherUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/location",
        ['latitude' => 33.5, 'longitude' => 36.2],
    )->assertForbidden();
});

/** @return array{0:User,1:Worker,2:CleaningBooking,3:CleaningBookingSession,4:CleaningBookingSessionWorkerAssignment} */
function makeSessionLocationScenario(): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => null,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 2,
    ]);
    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'fully_covered',
        'status' => CleaningBookingSessionStatus::WorkerAssigned->value,
        'base_price' => 3000,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 3300,
        'is_pricing_final' => true,
    ]);
    $assignment = CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'service_share_amount' => 3000,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'worker_amount' => 3000,
        'currency' => 'SYP',
    ]);

    return [$workerUser, $worker, $booking, $session, $assignment];
}
