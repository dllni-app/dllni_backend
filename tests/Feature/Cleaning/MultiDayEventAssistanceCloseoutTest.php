<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

beforeEach(function (): void {
    Event::fake([CleaningBookingTrackingUpdated::class]);
});

it('reschedules one future event day after an earlier day completed without touching siblings', function (): void {
    [$customer, $worker, $booking] = makeMultiDayEventCloseoutScenario();
    $completed = makeMultiDayEventCloseoutSession(
        $booking,
        sequence: 1,
        date: now()->subDay()->toDateString(),
        status: CleaningBookingSessionStatus::Completed,
    );
    $future = makeMultiDayEventCloseoutSession(
        $booking,
        sequence: 2,
        date: now()->addDays(3)->toDateString(),
        status: CleaningBookingSessionStatus::WorkerAssigned,
    );
    $assignment = makeMultiDayEventCloseoutAssignment($future, $worker);
    $completedPriceBefore = (float) $completed->total_price;
    $futureBaseBefore = (float) $future->base_price;
    $futureAdminBefore = (float) $future->admin_margin_amount;
    $futureTravelBefore = (float) $future->travel_fee;
    $futureExtensionBefore = (float) $future->extension_fee_total;
    $futurePriceBefore = (float) $future->total_price;
    $parentBaseBefore = (float) $booking->base_price;
    $parentAdminBefore = (float) $booking->admin_margin_amount;
    $parentTravelBefore = (float) $booking->travel_fee;
    $parentTotalBefore = (float) $booking->total_price;

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.canReschedule', true)
        ->assertJsonPath('data.schedule.sessions.0.canReschedule', true)
        ->assertJsonPath('data.schedule.sessions.0.canRescheduleSession', false)
        ->assertJsonPath('data.schedule.sessions.1.canReschedule', true)
        ->assertJsonPath('data.schedule.sessions.1.canRescheduleSession', true)
        ->assertJsonPath('data.schedule.sessions.1.canChangeDuration', false);

    $newDate = now()->addDays(5)->toDateString();

    $this->patchJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$future->id}/schedule",
        [
            'date' => $newDate,
            'time' => '14:30',
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.updatedSessionId', $future->id)
        ->assertJsonPath('data.schedule.sessions.0.sessionId', $completed->id)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Completed->value)
        ->assertJsonPath('data.schedule.sessions.0.canRescheduleSession', false)
        ->assertJsonPath('data.schedule.sessions.1.sessionId', $future->id)
        ->assertJsonPath('data.schedule.sessions.1.scheduledDate', $newDate)
        ->assertJsonPath('data.schedule.sessions.1.scheduledTime', '14:30')
        ->assertJsonPath('data.schedule.sessions.1.canRescheduleSession', true);

    expect($completed->fresh()->scheduled_date?->toDateString())->toBe(now()->subDay()->toDateString())
        ->and((string) $completed->fresh()->scheduled_time)->toBe('10:00')
        ->and((float) $completed->fresh()->total_price)->toBe($completedPriceBefore)
        ->and($future->fresh()->scheduled_date?->toDateString())->toBe($newDate)
        ->and((string) $future->fresh()->scheduled_time)->toBe('14:30')
        ->and((float) $future->fresh()->duration_hours)->toBe(2.0)
        ->and((float) $future->fresh()->base_price)->toBe($futureBaseBefore)
        ->and((float) $future->fresh()->admin_margin_amount)->toBe($futureAdminBefore)
        ->and((float) $future->fresh()->travel_fee)->toBe($futureTravelBefore)
        ->and((float) $future->fresh()->extension_fee_total)->toBe($futureExtensionBefore)
        ->and((float) $future->fresh()->total_price)->toBe($futurePriceBefore)
        ->and((float) $booking->fresh()->base_price)->toBe($parentBaseBefore)
        ->and((float) $booking->fresh()->admin_margin_amount)->toBe($parentAdminBefore)
        ->and((float) $booking->fresh()->travel_fee)->toBe($parentTravelBefore)
        ->and((float) $booking->fresh()->total_price)->toBe($parentTotalBefore)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::WorkerAssigned);

    Event::assertDispatched(
        CleaningBookingTrackingUpdated::class,
        function (CleaningBookingTrackingUpdated $event) use ($booking, $future): bool {
            return $event->cleaningBookingId === (int) $booking->id
                && ($event->tracking['action'] ?? null) === 'event_session_rescheduled'
                && (int) ($event->tracking['sessionId'] ?? 0) === (int) $future->id;
        },
    );
});

it('does not allow changing event day duration after a worker accepted that day', function (): void {
    [$customer, $worker, $booking] = makeMultiDayEventCloseoutScenario();
    $future = makeMultiDayEventCloseoutSession(
        $booking,
        sequence: 1,
        date: now()->addDays(3)->toDateString(),
        status: CleaningBookingSessionStatus::WorkerAssigned,
    );
    makeMultiDayEventCloseoutAssignment($future, $worker);
    $originalDate = $future->scheduled_date?->toDateString();
    $originalTime = (string) $future->scheduled_time;
    $sessionBaseBefore = (float) $future->base_price;
    $sessionAdminBefore = (float) $future->admin_margin_amount;
    $sessionTotalBefore = (float) $future->total_price;
    $parentBaseBefore = (float) $booking->base_price;
    $parentAdminBefore = (float) $booking->admin_margin_amount;
    $parentHoursBefore = (float) $booking->total_hours;
    $parentTotalBefore = (float) $booking->total_price;

    Sanctum::actingAs($customer);

    $this->patchJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$future->id}/schedule",
        [
            'date' => now()->addDays(4)->toDateString(),
            'time' => '11:00',
            'hours' => 3,
        ],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hours');

    expect((float) $future->fresh()->duration_hours)->toBe(2.0)
        ->and($future->fresh()->scheduled_date?->toDateString())->toBe($originalDate)
        ->and((string) $future->fresh()->scheduled_time)->toBe($originalTime)
        ->and((float) $future->fresh()->base_price)->toBe($sessionBaseBefore)
        ->and((float) $future->fresh()->admin_margin_amount)->toBe($sessionAdminBefore)
        ->and((float) $future->fresh()->total_price)->toBe($sessionTotalBefore)
        ->and((float) $booking->fresh()->base_price)->toBe($parentBaseBefore)
        ->and((float) $booking->fresh()->admin_margin_amount)->toBe($parentAdminBefore)
        ->and((float) $booking->fresh()->total_hours)->toBe($parentHoursBefore)
        ->and((float) $booking->fresh()->total_price)->toBe($parentTotalBefore);
});

it('stops exposing the event day edit action after assigned worker travel begins', function (): void {
    [$customer, $worker, $booking] = makeMultiDayEventCloseoutScenario();
    $future = makeMultiDayEventCloseoutSession(
        $booking,
        sequence: 1,
        date: now()->addDays(3)->toDateString(),
        status: CleaningBookingSessionStatus::WorkerAssigned,
    );
    $assignment = makeMultiDayEventCloseoutAssignment($future, $worker);
    $assignment->forceFill(['started_travel_at' => now()])->save();

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.canReschedule', false)
        ->assertJsonPath('data.schedule.sessions.0.canReschedule', false)
        ->assertJsonPath('data.schedule.sessions.0.canRescheduleSession', false)
        ->assertJsonPath('data.schedule.sessions.0.canChangeDuration', false);

    $this->patchJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$future->id}/schedule",
        [
            'date' => now()->addDays(5)->toDateString(),
            'time' => '12:00',
        ],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('session');
});

it('rejects a future day edit that would double book an accepted worker', function (): void {
    [$customer, $worker, $booking] = makeMultiDayEventCloseoutScenario();
    $future = makeMultiDayEventCloseoutSession(
        $booking,
        sequence: 1,
        date: now()->addDays(3)->toDateString(),
        status: CleaningBookingSessionStatus::WorkerAssigned,
    );
    makeMultiDayEventCloseoutAssignment($future, $worker);

    $otherBooking = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDays(6)->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 2,
    ]);
    $conflicting = makeMultiDayEventCloseoutSession(
        $otherBooking,
        sequence: 1,
        date: now()->addDays(6)->toDateString(),
        status: CleaningBookingSessionStatus::WorkerAssigned,
    );
    makeMultiDayEventCloseoutAssignment($conflicting, $worker);

    Sanctum::actingAs($customer);

    $this->patchJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$future->id}/schedule",
        [
            'date' => now()->addDays(6)->toDateString(),
            'time' => '10:30',
        ],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('time');

    expect($future->fresh()->scheduled_date?->toDateString())->toBe(now()->addDays(3)->toDateString())
        ->and((string) $future->fresh()->scheduled_time)->toBe('10:00');
});

it('forbids another customer from rescheduling an event day', function (): void {
    [$customer, $worker, $booking] = makeMultiDayEventCloseoutScenario();
    $future = makeMultiDayEventCloseoutSession(
        $booking,
        sequence: 1,
        date: now()->addDays(3)->toDateString(),
        status: CleaningBookingSessionStatus::WorkerAssigned,
    );
    makeMultiDayEventCloseoutAssignment($future, $worker);
    $otherCustomer = User::factory()->create(['is_active' => true]);

    Sanctum::actingAs($otherCustomer);

    $this->patchJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$future->id}/schedule",
        [
            'date' => now()->addDays(5)->toDateString(),
            'time' => '12:00',
        ],
    )->assertForbidden();

    expect($future->fresh()->scheduled_date?->toDateString())->toBe(now()->addDays(3)->toDateString());
});

/** @return array{0:User,1:Worker,2:CleaningBooking} */
function makeMultiDayEventCloseoutScenario(): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'property_type' => 'event_assistance',
        'property_details' => [
            'event_type' => 'birthday',
            'guest_count' => 25,
            'venue_type' => 'house',
            'custom_service' => 'Event support',
            'hours' => 4,
        ],
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'number_of_workers' => 1,
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 4,
        'total_hours' => 4,
        'base_price' => 6000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 600,
        'cancellation_fee' => 0,
        'total_price' => 6600,
    ]);

    return [$customer, $worker, $booking];
}

function makeMultiDayEventCloseoutSession(
    CleaningBooking $booking,
    int $sequence,
    string $date,
    CleaningBookingSessionStatus $status,
): CleaningBookingSession {
    $completed = $status === CleaningBookingSessionStatus::Completed;

    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_assistance',
        'calculation_mode' => 'hours',
        'scheduled_date' => $date,
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => CleaningBookingSessionCoverageStatus::FullyCovered,
        'status' => $status,
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
        'work_started_at' => $completed ? now()->subHours(3) : null,
        'work_finished_at' => $completed ? now()->subHour() : null,
    ]);
}

function makeMultiDayEventCloseoutAssignment(
    CleaningBookingSession $session,
    Worker $worker,
): CleaningBookingSessionWorkerAssignment {
    return CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now()->subHour(),
        'service_share_amount' => 3000,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'worker_amount' => 3000,
        'currency' => 'SYP',
    ]);
}
