<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\ArrivalVerified;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;

it('allows a worker assigned only to a child session to view the parent booking details', function (): void {
    [, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $session = makeLifecycleSession(
        $booking,
        1,
        now()->addDay()->toDateString(),
        '10:00',
    );
    $assignment = makeLifecycleAssignment($session, $worker);

    $booking->forceFill([
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'worker_id' => null,
    ])->save();
    $session->forceFill([
        'status' => CleaningBookingSessionStatus::TimeExtensionRequested,
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
    ])->save();

    Sanctum::actingAs($workerUser);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $booking->id);
});

it('broadcasts the session start approval immediately after the customer verifies the code', function (): void {
    Event::fake([ArrivalVerified::class, CleaningBookingTrackingUpdated::class]);

    [$customer, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $session = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    makeLifecycleAssignment($session, $worker);

    Sanctum::actingAs($workerUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/start-travel")
        ->assertOk();
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/arrive")
        ->assertOk();
    $securityCode = (string) $this->getJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/security-code",
    )->assertOk()->json('data.securityCode');

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/start-verification/confirm",
        ['code' => $securityCode],
    )->assertOk();

    Event::assertDispatched(ArrivalVerified::class, function (ArrivalVerified $event) use ($booking, $session, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->sessionId === $session->id;
    });
    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking, $session): bool {
        return $event->cleaningBookingId === $booking->id
            && (int) ($event->tracking['sessionId'] ?? 0) === $session->id
            && ($event->tracking['sessionStatus'] ?? null) === CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation->value;
    });
});

it('rejects completion for only the selected session and broadcasts the decision to its worker', function (): void {
    Event::fake([CleaningBookingTrackingUpdated::class, CompletionDecisionMade::class]);

    [$customer, , $worker, $booking] = makeLifecycleScenario();
    $first = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $second = makeLifecycleSession($booking, 2, now()->addDays(2)->toDateString(), '10:00');
    $assignment = makeLifecycleAssignment($first, $worker);

    $first->forceFill([
        'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
        'payment_status' => 'ready',
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
        'started_travel_at' => now()->subHours(3),
        'arrived_at' => now()->subHours(2),
        'start_approved_at' => now()->subHours(2),
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
        'worker_completion_message' => 'done',
    ])->save();
    $booking->forceFill([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
    ])->save();

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/completion/reject",
        ['reason' => 'بحاجة إلى متابعة'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::InProgress->value)
        ->assertJsonPath('data.schedule.sessions.1.id', $second->id);

    expect($first->fresh()->status)->toBe(CleaningBookingSessionStatus::InProgress)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::InProgress)
        ->and($assignment->fresh()->work_finished_at)->toBeNull()
        ->and($second->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::InProgress);

    Event::assertDispatched(CompletionDecisionMade::class, function (CompletionDecisionMade $event) use ($booking, $first, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->sessionId === $first->id
            && $event->decision === 'rejected';
    });
});

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
        ->assertJsonPath('data.status', CleaningBookingStatus::WorkerAssigned->value)
        ->assertJsonPath('data.schedule.completedDaysCount', 1)
        ->assertJsonPath('data.schedule.remainingDaysCount', 1)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Completed->value)
        ->assertJsonPath('data.schedule.sessions.1.id', $second->id)
        ->assertJsonPath('data.reviewTarget.sessionId', $first->id)
        ->assertJsonPath('data.reviewTarget.workerIds.0', $worker->id);

    expect($first->fresh()->status)->toBe(CleaningBookingSessionStatus::Completed)
        ->and($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Completed)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Completed)
        ->and($second->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned);
});

it('accepts a session security code through the legacy customer booking verification endpoint', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $session = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $assignment = makeLifecycleAssignment($session, $worker);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/start-travel")
        ->assertOk();
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/arrive")
        ->assertOk();

    $securityCode = (string) $this->getJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/security-code",
    )->assertOk()->json('data.securityCode');

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", [
        'code' => $securityCode,
    ])->assertOk();

    expect($session->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation)
        ->and($assignment->fresh()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::StartApproved)
        ->and($assignment->fresh()->start_approved_at)
        ->not->toBeNull();
});

it('rejects ambiguous legacy completion but allows rejecting the selected child session', function (): void {
    [$customer, , $worker, $booking] = makeLifecycleScenario();
    $first = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $second = makeLifecycleSession($booking, 2, now()->addDays(2)->toDateString(), '10:00');
    $assignment = makeLifecycleAssignment($first, $worker);

    $first->forceFill([
        'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
        'started_travel_at' => now()->subHours(3),
        'arrived_at' => now()->subHours(2),
        'start_approved_at' => now()->subHours(2),
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
    ])->save();
    $booking->forceFill([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
    ])->save();

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/reject", [
        'reason' => 'أكمل بعض التفاصيل',
    ])->assertUnprocessable()->assertJsonValidationErrors('session');

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/completion/reject", [
        'reason' => 'أكمل بعض التفاصيل',
    ])->assertOk();

    expect($first->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::InProgress)
        ->and($assignment->fresh()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::InProgress)
        ->and($second->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)
        ->toBe(CleaningBookingStatus::InProgress);
});

it('rejects ambiguous legacy completion and confirms only the selected child without completing future sessions', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $first = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $second = makeLifecycleSession($booking, 2, now()->addDays(2)->toDateString(), '10:00');
    $firstAssignment = makeLifecycleAssignment($first, $worker);
    makeLifecycleAssignment($second, $worker);

    $first->forceFill([
        'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
    ])->save();
    $firstAssignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
        'started_travel_at' => now()->subHours(3),
        'arrived_at' => now()->subHours(2),
        'start_approved_at' => now()->subHours(2),
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
    ])->save();
    $booking->forceFill([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
    ])->save();

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/confirm")
        ->assertUnprocessable()->assertJsonValidationErrors('session');
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/completion/confirm")
        ->assertOk();

    expect($first->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::Completed)
        ->and($firstAssignment->fresh()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::Completed)
        ->and($second->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)
        ->toBe(CleaningBookingStatus::WorkerAssigned);
});

it('accepts a legacy extension warning without session id by resolving the worker child session', function (): void {
    Queue::fake();

    [, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $session = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $assignment = makeLifecycleAssignment($session, $worker);

    $session->forceFill([
        'status' => CleaningBookingSessionStatus::TimeExtensionRequested,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
        'payment_status' => 'ready',
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
    ])->save();
    $booking->forceFill([
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'worker_id' => null,
    ])->save();

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'cleaning_booking_session_id' => null,
        'worker_id' => $worker->id,
        'customer_response' => 'extend_time',
        'worker_response' => null,
        'sent_at' => now(),
        'customer_responded_at' => now(),
        'worker_responded_at' => null,
        'additional_minutes' => 30,
        'quoted_base_amount' => 0,
        'quoted_admin_margin_amount' => 0,
        'quoted_amount' => 0,
        'quoted_currency' => 'SYP',
    ]);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.sessionId', $session->id);

    expect((int) $warning->fresh()->cleaning_booking_session_id)
        ->toBe($session->id)
        ->and($session->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::InProgress)
        ->and($assignment->fresh()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::InProgress);
});

it('rejects a legacy extension warning without session id by resolving the worker child session', function (): void {
    Queue::fake();

    [, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $session = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $assignment = makeLifecycleAssignment($session, $worker);

    $session->forceFill([
        'status' => CleaningBookingSessionStatus::TimeExtensionRequested,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
        'payment_status' => 'ready',
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
    ])->save();
    $booking->forceFill([
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'worker_id' => null,
    ])->save();

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'cleaning_booking_session_id' => null,
        'worker_id' => $worker->id,
        'customer_response' => 'extend_time',
        'worker_response' => null,
        'sent_at' => now(),
        'customer_responded_at' => now(),
        'worker_responded_at' => null,
        'additional_minutes' => 30,
        'quoted_base_amount' => 0,
        'quoted_admin_margin_amount' => 0,
        'quoted_amount' => 0,
        'quoted_currency' => 'SYP',
    ]);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => 'لا أستطيع التمديد',
    ])
        ->assertOk()
        ->assertJsonPath('data.sessionId', $session->id);

    expect((int) $warning->fresh()->cleaning_booking_session_id)
        ->toBe($session->id)
        ->and($session->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::AwaitingCustomerCompletion)
        ->and($assignment->fresh()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion);
});

it('routes a customer time-extension request to the selected child session and lets its worker accept it', function (): void {
    Queue::fake();

    [$customer, $workerUser, $worker, $booking] = makeLifecycleScenario();
    $first = makeLifecycleSession($booking, 1, now()->addDay()->toDateString(), '10:00');
    $second = makeLifecycleSession($booking, 2, now()->addDays(2)->toDateString(), '10:00');
    $assignment = makeLifecycleAssignment($first, $worker);
    makeLifecycleAssignment($second, $worker);

    $first->forceFill([
        'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
        'payment_status' => 'ready',
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
        'started_travel_at' => now()->subHours(3),
        'arrived_at' => now()->subHours(2),
        'start_approved_at' => now()->subHours(2),
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinute(),
    ])->save();
    $booking->forceFill([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
    ])->save();

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/extend-time", [
        'additionalMinutes' => 30,
        'workerId' => $worker->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('session');

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/extend-time", [
        'additionalMinutes' => 30,
        'sessionId' => $first->id,
        'workerId' => $worker->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::TimeExtensionRequested->value);

    $warning = CleaningTimeWarning::query()
        ->where('booking_id', $booking->id)
        ->where('cleaning_booking_session_id', $first->id)
        ->latest('id')
        ->firstOrFail();

    expect($first->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::TimeExtensionRequested)
        ->and($assignment->fresh()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested)
        ->and($second->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)
        ->toBe(CleaningBookingStatus::TimeExtensionRequested)
        ->and((int) $warning->worker_id)
        ->toBe($worker->id);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.sessionId', $first->id);

    expect($first->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::InProgress)
        ->and($first->fresh()->work_finished_at)
        ->toBeNull()
        ->and($assignment->fresh()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::InProgress)
        ->and($assignment->fresh()->work_finished_at)
        ->toBeNull()
        ->and($second->fresh()->status)
        ->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)
        ->toBe(CleaningBookingStatus::InProgress)
        ->and($warning->fresh()->worker_responded_at)
        ->not->toBeNull();
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
