<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
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
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

beforeEach(function (): void {
    Notification::fake();
    CleaningFinancialSetting::query()->delete();
    CleaningFinancialSetting::query()->create([
        ...CleaningRuntimeSettings::financialDefaults(),
        'user_cancellation_fee' => 0,
    ]);
});

it('replaces the worker for one recurring visit without touching the remaining visits', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    $firstAssignment = makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    $secondAssignment = makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/change-workers",
        [
            'changes' => [[
                'sessionId' => $firstSession->id,
                'workerIds' => [$worker->id],
            ]],
            'reason' => 'نحتاج عاملاً بديلاً لهذه الزيارة فقط',
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.workerChange.changedSessionIds.0', $firstSession->id)
        ->assertJsonPath('data.workerChange.releasedAssignments.0.workerId', $worker->id)
        ->assertJsonPath('data.schedule.sessions.0.sessionType', 'recurring_cleaning')
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.0.coverageStatus', CleaningBookingSessionCoverageStatus::Searching->value)
        ->assertJsonPath('data.schedule.sessions.0.acceptedWorkers', 0)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::WorkerAssigned->value)
        ->assertJsonPath('data.schedule.sessions.1.acceptedWorkers', 1);

    expect($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($firstAssignment->fresh()->released_reason)->toContain('Customer requested worker replacement')
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($firstSession->fresh()->coverage_status)->toBe(CleaningBookingSessionCoverageStatus::Searching)
        ->and($secondSession->fresh()->coverage_status)->toBe(CleaningBookingSessionCoverageStatus::FullyCovered)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Cancelled);

    $this->assertDatabaseMissing('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_session_id' => $firstSession->id,
        'worker_id' => $worker->id,
    ]);
});

it('treats worker absence as a single recurring visit withdrawal and keeps the series alive', function (): void {
    [, $workerUser, $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    $firstAssignment = makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    $secondAssignment = makeRecurringWorkerContinuityAssignment($secondSession, $worker);
    $trustBefore = (int) $worker->trust_score;

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$firstSession->id}/cancel",
        ['reason' => 'لن أستطيع الحضور لهذه الزيارة'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.sessionType', 'recurring_cleaning')
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.0.coverageStatus', CleaningBookingSessionCoverageStatus::Searching->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::WorkerAssigned->value)
        ->assertJsonPath('data.schedule.sessions.1.acceptedWorkers', 1);

    expect($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($firstSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Scheduled)
        ->and($secondSession->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Cancelled)
        ->and((int) $worker->fresh()->trust_score)->toBeLessThan($trustBefore);
});

it('keeps worker replacement unavailable for ordinary non-recurring cleaning sessions', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1, 'regular_cleaning');
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/change-workers",
        [
            'changes' => [[
                'sessionId' => $session->id,
                'workerIds' => [$worker->id],
            ]],
            'reason' => 'محاولة تغيير عامل حجز مفرد',
        ],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('changes');

    expect($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

it('lets the customer skip one future recurring visit without cancelling the series', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    $firstAssignment = makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    $secondAssignment = makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', true)
        ->assertJsonPath('data.schedule.sessions.1.canSkip', true);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$firstSession->id}/skip",
        ['reason' => 'لن نحتاج زيارة التنظيف في هذا الموعد'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Skipped->value)
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::WorkerAssigned->value)
        ->assertJsonPath('data.schedule.sessions.1.canSkip', true);

    expect($firstSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Skipped)
        ->and($firstSession->fresh()->skip_source)->toBe('customer')
        ->and($firstSession->fresh()->skip_reason)->toBe('لن نحتاج زيارة التنظيف في هذا الموعد')
        ->and($firstSession->fresh()->skipped_at)->not->toBeNull()
        ->and((float) $firstSession->fresh()->cancellation_fee)->toBe(0.0)
        ->and($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($firstAssignment->fresh()->released_reason)->toContain('Customer skipped recurring session')
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($secondSession->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Cancelled)
        ->and((float) $booking->fresh()->total_hours)->toBe(2.0)
        ->and((float) $booking->fresh()->total_price)->toBe(3300.0);

    $this->assertDatabaseMissing('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_session_id' => $firstSession->id,
    ]);
});

it('rejects skipping a recurring visit whose scheduled start is not in the future', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1);
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);
    $session->forceFill([
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => now()->subHour()->format('H:i'),
    ])->save();

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي زيارة انتهى موعدها'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

it('does not advertise skip after an assigned worker starts travel', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1);
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);
    $assignment->forceFill(['started_travel_at' => now()])->save();

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي زيارة بعد بدء الطريق'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

it('does not expose recurring skip for an ordinary cleaning session', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1, 'regular_cleaning');
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي جلسة غير دورية'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

it('pauses future recurring visits, releases workers and blocks acceptance until resume', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    $firstAssignment = makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    $secondAssignment = makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.isRecurring', true)
        ->assertJsonPath('data.schedule.isPaused', false)
        ->assertJsonPath('data.schedule.canPause', true)
        ->assertJsonPath('data.schedule.canResume', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'إيقاف الزيارات لأسبوعين'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.isPaused', true)
        ->assertJsonPath('data.schedule.canPause', false)
        ->assertJsonPath('data.schedule.canResume', true)
        ->assertJsonPath('data.schedule.pauseReason', 'إيقاف الزيارات لأسبوعين')
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Paused->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Paused->value);

    expect($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($firstSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Paused)
        ->and($secondSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Paused)
        ->and($booking->fresh()->recurring_paused_at)->not->toBeNull()
        ->and($booking->fresh()->recurring_pause_reason)->toBe('إيقاف الزيارات لأسبوعين');

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        ['sessionIds' => [$firstSession->id]],
    )
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.acceptance.rejected.0.reasonCode', 'session_paused');
});

it('resumes paused future visits and makes them available for acceptance again', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'توقف مؤقت'],
    )->assertOk();

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/resume",
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.isPaused', false)
        ->assertJsonPath('data.schedule.canPause', true)
        ->assertJsonPath('data.schedule.canResume', false)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Scheduled->value);

    expect($booking->fresh()->recurring_paused_at)->toBeNull()
        ->and($booking->fresh()->recurring_pause_reason)->toBeNull();

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 10000,
            'debt_balance' => 0,
            'deposited_total' => 10000,
            'withdrawn_total' => 0,
            'admin_revenue_withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 0,
            'is_active' => true,
        ],
    );
    $worker->forceFill([
        'default_working_hours' => [
            'monday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'tuesday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'wednesday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'thursday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'friday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'saturday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'sunday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        ],
    ])->save();
    $worker->unsetRelation('deposit');

    Sanctum::actingAs($workerUser);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        ['sessionIds' => [$firstSession->id]],
    )
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.acceptance.acceptedSessionIds.0', $firstSession->id);
});

it('turns visits that expired during a pause into penalty-free skipped visits on resume', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'توقف مؤقت'],
    )->assertOk();

    $firstSession->forceFill([
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => now()->subHour()->format('H:i'),
    ])->save();

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/resume",
    )
        ->assertOk()
        ->assertJsonPath('data.seriesAction.expiredSessionIds.0', $firstSession->id)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Skipped->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Scheduled->value);

    expect($firstSession->fresh()->skip_source)->toBe('recurring_pause_expired')
        ->and((float) $firstSession->fresh()->cancellation_fee)->toBe(0.0)
        ->and((float) $booking->fresh()->total_hours)->toBe(2.0)
        ->and((float) $booking->fresh()->total_price)->toBe(3300.0);

    $this->assertDatabaseMissing('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_session_id' => $firstSession->id,
    ]);
});

it('rejects pausing an ordinary non-recurring cleaning booking', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1, 'regular_cleaning');
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'محاولة غير صالحة'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

/** @return array{0:User,1:User,2:Worker,3:CleaningBooking} */
function makeRecurringWorkerContinuityScenario(): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
        'home_address' => 'Damascus',
        'home_latitude' => 33.5138,
        'home_longitude' => 36.2765,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'gender_preference' => 'any',
        'property_type' => 'apartment',
        'address_latitude' => 33.5100,
        'address_longitude' => 36.2900,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDays(2)->toDateString(),
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

    return [$customer, $workerUser, $worker, $booking];
}

function makeRecurringWorkerContinuitySession(
    CleaningBooking $booking,
    int $sequence,
    string $sessionType = 'recurring_cleaning',
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => $sessionType,
        'calculation_mode' => 'estimated_hours',
        'scheduled_date' => now()->addDays($sequence + 1)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => CleaningBookingSessionCoverageStatus::FullyCovered,
        'status' => CleaningBookingSessionStatus::WorkerAssigned,
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

function makeRecurringWorkerContinuityAssignment(
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
