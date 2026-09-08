<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
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

it('treats a repeated recurring pause request as an idempotent retry', function (): void {
    [$customer, $worker, $booking] = makeRecurringPauseRetryScenario();
    $firstSession = makeRecurringPauseRetrySession($booking, 1);
    $secondSession = makeRecurringPauseRetrySession($booking, 2);
    $firstAssignment = makeRecurringPauseRetryAssignment($firstSession, $worker);
    $secondAssignment = makeRecurringPauseRetryAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'توقف مؤقت'],
    )
        ->assertOk()
        ->assertJsonPath('data.seriesAction.pausedSessionIds.0', $firstSession->id)
        ->assertJsonPath('data.seriesAction.pausedSessionIds.1', $secondSession->id);

    $firstVersion = (int) $firstSession->fresh()->version;
    $secondVersion = (int) $secondSession->fresh()->version;
    $firstReleasedAt = $firstAssignment->fresh()->released_at?->toIso8601String();
    $secondReleasedAt = $secondAssignment->fresh()->released_at?->toIso8601String();
    $pausedAt = $booking->fresh()->recurring_paused_at?->toIso8601String();

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'توقف مؤقت'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.isPaused', true)
        ->assertJsonPath('data.seriesAction.pausedSessionIds.0', $firstSession->id)
        ->assertJsonPath('data.seriesAction.pausedSessionIds.1', $secondSession->id)
        ->assertJsonPath('data.seriesAction.releasedWorkerIds', []);

    expect((int) $firstSession->fresh()->version)->toBe($firstVersion)
        ->and((int) $secondSession->fresh()->version)->toBe($secondVersion)
        ->and($firstAssignment->fresh()->released_at?->toIso8601String())->toBe($firstReleasedAt)
        ->and($secondAssignment->fresh()->released_at?->toIso8601String())->toBe($secondReleasedAt)
        ->and($booking->fresh()->recurring_paused_at?->toIso8601String())->toBe($pausedAt)
        ->and($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled);
});

it('treats a repeated recurring resume request as a no-op against canonical resumed state', function (): void {
    [$customer, $worker, $booking] = makeRecurringPauseRetryScenario();
    $firstSession = makeRecurringPauseRetrySession($booking, 1);
    $secondSession = makeRecurringPauseRetrySession($booking, 2);
    makeRecurringPauseRetryAssignment($firstSession, $worker);
    makeRecurringPauseRetryAssignment($secondSession, $worker);

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
        ->assertJsonPath('data.seriesAction.resumedSessionIds.0', $firstSession->id)
        ->assertJsonPath('data.seriesAction.resumedSessionIds.1', $secondSession->id);

    $firstVersion = (int) $firstSession->fresh()->version;
    $secondVersion = (int) $secondSession->fresh()->version;

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/resume",
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.isPaused', false)
        ->assertJsonPath('data.schedule.canResume', false)
        ->assertJsonPath('data.seriesAction.resumedSessionIds', [])
        ->assertJsonPath('data.seriesAction.expiredSessionIds', [])
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Scheduled->value);

    expect((int) $firstSession->fresh()->version)->toBe($firstVersion)
        ->and((int) $secondSession->fresh()->version)->toBe($secondVersion)
        ->and($booking->fresh()->recurring_paused_at)->toBeNull()
        ->and($booking->fresh()->recurring_pause_reason)->toBeNull();
});

it('pauses only eligible future visits when another recurring visit has already started travel', function (): void {
    [$customer, $worker, $booking] = makeRecurringPauseRetryScenario();
    $inFlightSession = makeRecurringPauseRetrySession($booking, 1);
    $futureSession = makeRecurringPauseRetrySession($booking, 2);
    $inFlightAssignment = makeRecurringPauseRetryAssignment($inFlightSession, $worker);
    $futureAssignment = makeRecurringPauseRetryAssignment($futureSession, $worker);
    $inFlightAssignment->forceFill(['started_travel_at' => now()])->save();

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'إيقاف الزيارات القادمة فقط'],
    )
        ->assertOk()
        ->assertJsonPath('data.seriesAction.pausedSessionIds.0', $futureSession->id)
        ->assertJsonCount(1, 'data.seriesAction.pausedSessionIds')
        ->assertJsonPath('data.schedule.isPaused', true);

    expect($inFlightSession->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($inFlightAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($futureSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Paused)
        ->and($futureAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/resume",
    )
        ->assertOk()
        ->assertJsonPath('data.seriesAction.resumedSessionIds.0', $futureSession->id)
        ->assertJsonCount(1, 'data.seriesAction.resumedSessionIds');

    expect($inFlightSession->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($inFlightAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($futureSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Scheduled);
});

it('forbids another customer from pausing or resuming the recurring series', function (): void {
    [$customer, $worker, $booking] = makeRecurringPauseRetryScenario();
    $session = makeRecurringPauseRetrySession($booking, 1);
    $assignment = makeRecurringPauseRetryAssignment($session, $worker);
    $otherCustomer = User::factory()->create(['is_active' => true]);

    Sanctum::actingAs($otherCustomer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'محاولة غير مصرح بها'],
    )->assertForbidden();

    expect($booking->fresh()->recurring_paused_at)->toBeNull()
        ->and($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'توقف شرعي'],
    )->assertOk();

    Sanctum::actingAs($otherCustomer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/resume",
    )->assertForbidden();

    expect($booking->fresh()->recurring_paused_at)->not->toBeNull()
        ->and($session->fresh()->status)->toBe(CleaningBookingSessionStatus::Paused);
});

/** @return array{0:User,1:Worker,2:CleaningBooking} */
function makeRecurringPauseRetryScenario(): array
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

    return [$customer, $worker, $booking];
}

function makeRecurringPauseRetrySession(CleaningBooking $booking, int $sequence): CleaningBookingSession
{
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
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

function makeRecurringPauseRetryAssignment(
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
