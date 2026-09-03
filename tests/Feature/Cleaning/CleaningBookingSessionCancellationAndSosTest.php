<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Models\CleaningFinancialSetting;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionFinancialPenalty;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

beforeEach(function (): void {
    Notification::fake();
    setSessionCancellationFee(250);
});

it('cancels only the selected future session and reaggregates parent financials', function (): void {
    [$customer, , $worker, $booking] = makeSessionCancellationScenario(3);
    $first = makeSessionCancellationSession($booking, 1, CleaningBookingSessionStatus::Completed);
    $second = makeSessionCancellationSession($booking, 2, CleaningBookingSessionStatus::WorkerAssigned);
    $third = makeSessionCancellationSession($booking, 3, CleaningBookingSessionStatus::WorkerAssigned);
    $assignment = makeSessionCancellationAssignment($second, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$second->id}/cancel",
        ['reason' => 'لن نحتاج الخدمة في اليوم الثاني'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.completedDaysCount', 1)
        ->assertJsonPath('data.schedule.cancelledDaysCount', 1)
        ->assertJsonPath('data.schedule.remainingDaysCount', 1)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Cancelled->value)
        ->assertJsonPath('data.schedule.sessions.1.pricing.cancellationFee', 250)
        ->assertJsonPath('data.schedule.sessions.2.canCancel', true);

    expect($first->fresh()->status)->toBe(CleaningBookingSessionStatus::Completed)
        ->and($second->fresh()->status)->toBe(CleaningBookingSessionStatus::Cancelled)
        ->and($third->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and((float) $booking->fresh()->total_hours)->toBe(4.0)
        ->and((float) $booking->fresh()->cancellation_fee)->toBe(250.0)
        ->and((float) $booking->fresh()->total_price)->toBe(6850.0)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Cancelled);

    $this->assertDatabaseHas('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $second->id,
        'customer_id' => $customer->id,
        'reference_key' => 'customer:'.$second->id,
        'penalized_role' => CleaningBookingSessionFinancialPenalty::ROLE_CUSTOMER,
        'amount' => 250,
    ]);
});

it('does not allow a completed session to be cancelled', function (): void {
    [$customer, , , $booking] = makeSessionCancellationScenario();
    $session = makeSessionCancellationSession($booking, 1, CleaningBookingSessionStatus::Completed);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/cancel",
        ['reason' => 'محاولة إلغاء يوم منفذ'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::Completed);
});

it('lets a worker withdraw from one future session without cancelling the customer session', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeSessionCancellationScenario(2);
    $session = makeSessionCancellationSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned);
    $other = makeSessionCancellationSession($booking, 2, CleaningBookingSessionStatus::WorkerAssigned);
    $assignment = makeSessionCancellationAssignment($session, $worker);
    $trustBefore = (int) $worker->trust_score;

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/cancel",
        ['reason' => 'تعذر الالتزام بهذا اليوم'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.0.coverageStatus', CleaningBookingSessionCoverageStatus::Searching->value)
        ->assertJsonPath('data.schedule.sessions.0.canCancel', false);

    expect($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($session->fresh()->status)->toBe(CleaningBookingSessionStatus::Scheduled)
        ->and($session->fresh()->coverage_status)->toBe(CleaningBookingSessionCoverageStatus::Searching)
        ->and($other->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Cancelled)
        ->and((int) $worker->fresh()->trust_score)->toBeLessThan($trustBefore);

    $reference = 'cleaning_session_cancellation_penalty:'.$session->id.':'.$worker->id;
    $this->assertDatabaseHas('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'reference_key' => $reference,
        'penalized_role' => CleaningBookingSessionFinancialPenalty::ROLE_WORKER,
        'amount' => 250,
    ]);
    $this->assertDatabaseHas('cleaning_deposit_transactions', [
        'worker_id' => $worker->id,
        'reference' => $reference,
        'amount' => 250,
    ]);

    Sanctum::actingAs($customer);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canCancel', true)
        ->assertJsonPath('data.schedule.sessions.0.canSendSos', true);
});

it('rejects worker withdrawal after that worker starts travel', function (): void {
    [, $workerUser, $worker, $booking] = makeSessionCancellationScenario();
    $session = makeSessionCancellationSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned);
    $assignment = makeSessionCancellationAssignment($session, $worker);
    $assignment->forceFill(['started_travel_at' => now()])->save();
    $session->forceFill(['started_travel_at' => now()])->save();

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/cancel",
        ['reason' => 'بعد بدء التوجه'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
    $this->assertDatabaseMissing('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
    ]);
});

it('creates an SOS with explicit session context for worker and customer actors', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeSessionCancellationScenario();
    $session = makeSessionCancellationSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned);
    makeSessionCancellationAssignment($session, $worker);

    Sanctum::actingAs($workerUser);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/sos",
        [
            'emergency_type' => 'safety_threat',
            'message' => 'حالة طارئة أثناء تجهيز اليوم',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
        ],
    )
        ->assertCreated()
        ->assertJsonPath('data.sos.source', 'booking_session:'.$session->id);

    $workerSos = SosAlert::query()
        ->where('user_id', $workerUser->id)
        ->where('source', 'booking_session:'.$session->id)
        ->firstOrFail();
    expect((int) $workerSos->booking_id)->toBe((int) $booking->id);

    $systemAlert = SystemAlert::query()
        ->where('booking_id', $booking->id)
        ->where('alert_type', AlertType::SOSTriggered->value)
        ->latest('id')
        ->firstOrFail();
    expect((int) data_get($systemAlert->payload, 'session_id'))->toBe((int) $session->id)
        ->and(data_get($systemAlert->payload, 'actor_role'))->toBe('worker');

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/sos",
        [
            'emergency_type' => 'safety_threat',
            'message' => 'أحتاج دعماً عاجلاً لهذا اليوم',
        ],
    )
        ->assertCreated()
        ->assertJsonPath('data.sos.source', 'booking_session:'.$session->id);

    $this->assertDatabaseHas('sos_alerts', [
        'user_id' => $customer->id,
        'booking_id' => $booking->id,
        'source' => 'booking_session:'.$session->id,
    ]);
});

it('exposes customer verification and completion capabilities by session status', function (): void {
    [$customer, , $worker, $booking] = makeSessionCancellationScenario(2);
    $verification = makeSessionCancellationSession($booking, 1, CleaningBookingSessionStatus::AwaitingStartVerification);
    $completion = makeSessionCancellationSession($booking, 2, CleaningBookingSessionStatus::AwaitingCustomerCompletion);
    makeSessionCancellationAssignment($verification, $worker, CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification);
    makeSessionCancellationAssignment($completion, $worker, CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion);

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canConfirmStartVerification', true)
        ->assertJsonPath('data.schedule.sessions.0.canConfirmCompletion', false)
        ->assertJsonPath('data.schedule.sessions.1.canConfirmStartVerification', false)
        ->assertJsonPath('data.schedule.sessions.1.canConfirmCompletion', true);
});

function setSessionCancellationFee(float $fee): void
{
    CleaningFinancialSetting::query()->delete();
    CleaningFinancialSetting::query()->create([
        ...CleaningRuntimeSettings::financialDefaults(),
        'user_cancellation_fee' => $fee,
    ]);
}

/** @return array{0:User,1:User,2:Worker,3:CleaningBooking} */
function makeSessionCancellationScenario(int $days = 1): array
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
        'total_hours' => $days * 2,
        'base_price' => $days * 3000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => $days * 300,
        'cancellation_fee' => 0,
        'total_price' => $days * 3300,
    ]);

    return [$customer, $workerUser, $worker, $booking];
}

function makeSessionCancellationSession(
    CleaningBooking $booking,
    int $sequence,
    CleaningBookingSessionStatus $status,
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->addDays($sequence)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => $status === CleaningBookingSessionStatus::Scheduled
            ? CleaningBookingSessionCoverageStatus::Searching
            : CleaningBookingSessionCoverageStatus::FullyCovered,
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
        'work_started_at' => in_array($status, [
            CleaningBookingSessionStatus::AwaitingCustomerCompletion,
            CleaningBookingSessionStatus::Completed,
        ], true) ? now()->subHour() : null,
        'work_finished_at' => in_array($status, [
            CleaningBookingSessionStatus::AwaitingCustomerCompletion,
            CleaningBookingSessionStatus::Completed,
        ], true) ? now() : null,
    ]);
}

function makeSessionCancellationAssignment(
    CleaningBookingSession $session,
    Worker $worker,
    CleaningBookingWorkerAssignmentStatus $status = CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
): CleaningBookingSessionWorkerAssignment {
    return CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => $status,
        'accepted_at' => now()->subDay(),
        'service_share_amount' => 3000,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'worker_amount' => 3000,
        'currency' => 'SYP',
        'arrived_at' => $status === CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification
            ? now()->subMinutes(5)
            : null,
        'work_started_at' => $status === CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion
            ? now()->subHour()
            : null,
        'work_finished_at' => $status === CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion
            ? now()
            : null,
    ]);
}
