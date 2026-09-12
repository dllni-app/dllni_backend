<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

it('runs one event day through its own lifecycle without completing future days', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $first = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $second = makeLifecycleSession($booking, 2, now()->addDays(2)->toDateString(), '10:00');
    $firstAssignment = makeLifecycleAssignment($first, $worker);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/start-travel")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.id', $first->id);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/arrive")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::AwaitingStartVerification->value);

    $securityCodeResponse = $this->getJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/security-code",
    )->assertOk();
    $securityCode = (string) $securityCodeResponse->json('data.securityCode');

    expect($securityCode)->toHaveLength(4);
    $this->assertDatabaseHas('booking_security_codes', [
        'booking_id' => $first->id,
        'booking_type' => $first->getMorphClass(),
        'worker_id' => $worker->id,
    ]);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/start-verification/confirm",
        ['code' => $securityCode],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation->value);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/start-work")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::InProgress->value);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/complete",
        ['message' => 'تم إنهاء اليوم الأول'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::AwaitingCustomerCompletion->value);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/completion/confirm")
        ->assertOk()
        ->assertJsonPath('data.schedule.completedDaysCount', 1)
        ->assertJsonPath('data.schedule.remainingDaysCount', 1)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Completed->value)
        ->assertJsonPath('data.schedule.sessions.1.id', $second->id);

    expect($first->fresh()->status)->toBe(CleaningBookingSessionStatus::Completed)
        ->and($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Completed)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Completed)
        ->and($second->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned);
});

it('completes the parent only when the final required event session is completed', function (): void {
    [$customer, , $worker, $booking] = makeLifecycleScenario();
    $first = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $second = makeLifecycleSession($booking, 2, now()->addDays(2)->toDateString(), '10:00');

    $first->forceFill([
        'status' => CleaningBookingSessionStatus::Completed,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subHour(),
    ])->save();

    $second->forceFill([
        'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
        'started_travel_at' => now()->subHours(2),
        'arrived_at' => now()->subHours(2),
        'customer_confirmed_at' => now()->subHours(2),
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now(),
    ])->save();

    CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $second->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
        'accepted_at' => now()->subDay(),
        'started_travel_at' => now()->subHours(2),
        'arrived_at' => now()->subHours(2),
        'start_approved_at' => now()->subHours(2),
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now(),
        'service_share_amount' => 3000,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'worker_amount' => 3000,
        'currency' => 'SYP',
    ]);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$second->id}/completion/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::Completed->value)
        ->assertJsonPath('data.schedule.completedDaysCount', 2)
        ->assertJsonPath('data.schedule.remainingDaysCount', 0);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Completed)
        ->and($second->fresh()->status)->toBe(CleaningBookingSessionStatus::Completed);
});

it('rejects a session lifecycle action when the session belongs to another parent booking', function (): void {
    [, $workerUser, $worker, $booking] = makeLifecycleScenario();
    [, , , $otherBooking] = makeLifecycleScenario();
    $foreignSession = makeLifecycleSession($otherBooking, 1, now()->addDay()->toDateString(), '13:00');
    makeLifecycleAssignment($foreignSession, $worker);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$foreignSession->id}/start-travel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

/** @return array{0:User,1:User,2:Worker,3:CleaningBooking} */
function makeLifecycleScenario(): array
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
        'preferred_worker_id' => null,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 4,
    ]);

    return [$customer, $workerUser, $worker, $booking];
}

function makeLifecycleSession(
    CleaningBooking $booking,
    int $sequence,
    string $date,
    string $time,
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
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
}

function makeLifecycleAssignment(
    CleaningBookingSession $session,
    Worker $worker,
): CleaningBookingSessionWorkerAssignment {
    return CleaningBookingSessionWorkerAssignment::query()->create([
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
}
