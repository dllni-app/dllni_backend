<?php

declare(strict_types=1);

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

beforeEach(function (): void {
    Notification::fake();
});

it('releases only the selected future event worker assignment', function (): void {
    [$customer, $firstWorker, $secondWorker, $booking] = makeWorkerChangeScenario();
    $firstSession = makeWorkerChangeSession($booking, 1, 2);
    $secondSession = makeWorkerChangeSession($booking, 2, 1);

    $firstAssignment = makeWorkerChangeAssignment($firstSession, $firstWorker);
    $secondAssignment = makeWorkerChangeAssignment($firstSession, $secondWorker);
    $untouchedAssignment = makeWorkerChangeAssignment($secondSession, $firstWorker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/change-workers",
        [
            'changes' => [[
                'sessionId' => $firstSession->id,
                'workerIds' => [$firstWorker->id],
            ]],
            'reason' => 'نريد استبدال هذا العامل لهذا اليوم فقط',
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.workerChange.changedSessionIds.0', $firstSession->id)
        ->assertJsonPath('data.workerChange.releasedAssignments.0.sessionId', $firstSession->id)
        ->assertJsonPath('data.workerChange.releasedAssignments.0.workerId', $firstWorker->id)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::WorkerAssigned->value)
        ->assertJsonPath('data.schedule.sessions.0.coverageStatus', CleaningBookingSessionCoverageStatus::PartiallyCovered->value)
        ->assertJsonPath('data.schedule.sessions.0.acceptedWorkers', 1)
        ->assertJsonPath('data.schedule.sessions.0.remainingWorkers', 1)
        ->assertJsonPath('data.schedule.sessions.1.acceptedWorkers', 1);

    expect($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($firstAssignment->fresh()->released_reason)->toContain('Customer requested worker replacement')
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($untouchedAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($firstSession->fresh()->coverage_status)->toBe(CleaningBookingSessionCoverageStatus::PartiallyCovered)
        ->and($secondSession->fresh()->coverage_status)->toBe(CleaningBookingSessionCoverageStatus::FullyCovered)
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::WorkerAssigned);

    $this->assertDatabaseMissing('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_session_id' => $firstSession->id,
        'worker_id' => $firstWorker->id,
    ]);
});

it('keeps a multi-session worker change atomic when one selected worker already started travel', function (): void {
    [$customer, $firstWorker, $secondWorker, $booking] = makeWorkerChangeScenario();
    $firstSession = makeWorkerChangeSession($booking, 1, 1);
    $secondSession = makeWorkerChangeSession($booking, 2, 1);

    $firstAssignment = makeWorkerChangeAssignment($firstSession, $firstWorker);
    $secondAssignment = makeWorkerChangeAssignment($secondSession, $secondWorker);
    $secondAssignment->forceFill(['started_travel_at' => now()])->save();

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/change-workers",
        [
            'changes' => [
                [
                    'sessionId' => $firstSession->id,
                    'workerIds' => [$firstWorker->id],
                ],
                [
                    'sessionId' => $secondSession->id,
                    'workerIds' => [$secondWorker->id],
                ],
            ],
            'reason' => 'تغيير الفريق للأيام القادمة',
        ],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('changes');

    expect($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($firstAssignment->fresh()->released_at)->toBeNull()
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($firstSession->fresh()->coverage_status)->toBe(CleaningBookingSessionCoverageStatus::FullyCovered)
        ->and($secondSession->fresh()->coverage_status)->toBe(CleaningBookingSessionCoverageStatus::FullyCovered);
});

it('rejects worker changes for a non-event cleaning booking', function (): void {
    [$customer, $firstWorker, , $booking] = makeWorkerChangeScenario();
    $booking->forceFill(['property_type' => 'apartment'])->save();
    $session = makeWorkerChangeSession($booking, 1, 1);
    $assignment = makeWorkerChangeAssignment($session, $firstWorker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/change-workers",
        [
            'changes' => [[
                'sessionId' => $session->id,
                'workerIds' => [$firstWorker->id],
            ]],
            'reason' => 'محاولة تغيير عامل',
        ],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('changes');

    expect($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

/** @return array{0:User,1:Worker,2:Worker,3:CleaningBooking} */
function makeWorkerChangeScenario(): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $firstWorkerUser = User::factory()->create(['is_active' => true]);
    $secondWorkerUser = User::factory()->create(['is_active' => true]);
    $firstWorker = Worker::factory()->create([
        'user_id' => $firstWorkerUser->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $secondWorker = Worker::factory()->create([
        'user_id' => $secondWorkerUser->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'number_of_workers' => 2,
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

    return [$customer, $firstWorker, $secondWorker, $booking];
}

function makeWorkerChangeSession(
    CleaningBooking $booking,
    int $sequence,
    int $requiredWorkers,
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->addDays($sequence + 1)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => $requiredWorkers,
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

function makeWorkerChangeAssignment(
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
