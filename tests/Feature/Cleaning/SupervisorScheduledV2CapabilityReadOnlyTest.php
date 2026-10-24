<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Modules\Cleaning\Services\CleaningV2VisitExecutionCapabilityService;

function g2aCapabilityFixture(array $sessionOverrides = [], array $bookingOverrides = []): array
{
    $booking = CleaningBooking::factory()->create($bookingOverrides + [
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'is_pricing_final' => true,
        'property_details' => ['_supervisor_pricing' => ['fee' => 100, 'hourlyRate' => 100]],
    ]);
    $session = $booking->sessions()->create($sessionOverrides + [
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(4)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'is_pricing_final' => true,
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'capability.visit'],
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'capability.room',
        'client_key' => 'capability.room',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Capability room',
        'weight' => 1,
    ]);
    $scheduleStart = CarbonImmutable::parse(
        $session->scheduled_date->toDateString().' 09:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
    )->utc();
    $details = is_array($booking->property_details) ? $booking->property_details : [];
    $details['_room_period_v2_approved_total'] = (float) $booking->total_price;
    $details['_time_period_v2'] = [
        'contractVersion' => 2,
        'requiredSimultaneousWorkers' => (int) $booking->number_of_workers,
        'reconciliationRequired' => false,
        'pricing' => [
            'isPricingFinal' => true,
            'totalPrice' => (float) $booking->total_price,
        ],
        'schedulingPolicy' => [
            'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        ],
        'visits' => [[
            'sequence' => (int) $session->sequence,
            'visitKey' => 'capability.visit',
            'estimatedDurationMinutes' => 120,
            'requiredSimultaneousWorkers' => $session->requiredWorkerCount(),
            'roomSchedules' => [[
                'scheduleKey' => 'capability.schedule',
                'roomKey' => 'capability.room',
                'startAt' => $scheduleStart->setTimezone('Asia/Damascus')->toIso8601String(),
                'endAt' => $scheduleStart->addHours(2)->setTimezone('Asia/Damascus')->toIso8601String(),
                'numberOfWorkers' => 1,
                'estimatedWorkMinutes' => 120,
                'estimatedDurationMinutes' => 120,
            ]],
        ]],
    ];
    $booking->forceFill(['property_details' => $details])->save();
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'capability.schedule',
        'visit_key' => 'capability.visit',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => $scheduleStart,
        'ends_at' => $scheduleStart->addHours(2),
        'required_workers' => 1,
        'work_minutes' => 120,
        'duration_minutes' => 120,
    ]);
    $actor = User::factory()->create();
    $supervisor = User::factory()->create(['is_active' => true]);

    return [$booking, $session, $actor, $supervisor];
}

it('reports private-safe readiness while keeping every G2A action disabled', function (): void {
    [$booking, $session, $actor, $supervisor] = g2aCapabilityFixture();
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 20000,
        'active_slot' => 1,
    ]);

    $readiness = app(CleaningV2VisitExecutionCapabilityService::class)->readiness($booking, $session);
    expect($readiness['executionCapability'])->toBeFalse()
        ->and($readiness['allowedActions'])->toBe([])
        ->and($readiness['reasonCodes'])->toContain('task_worker_membership_unproven')
        ->and($readiness['taskCandidates'])->toBe([[
            'taskKey' => 'schedule:capability.schedule',
            'sourceKind' => 'room_schedule',
            'requiredWorkers' => 1,
        ]])
        ->and(array_keys($readiness))->not->toContain('customerCode', 'compensationHourlyRate', 'workerLocations', 'supervisorAssignmentId');
});

it('fails closed for missing ownership compensation initialization and terminal state', function (): void {
    [$booking, $session] = g2aCapabilityFixture([
        'supervision_initialized_at' => null,
        'supervision_mode' => null,
        'status' => 'cancelled',
    ], [
        'status' => 'cancelled',
    ]);

    $readiness = app(CleaningV2VisitExecutionCapabilityService::class)->readiness($booking, $session);
    expect($readiness['executionCapability'])->toBeFalse()
        ->and($readiness['allowedActions'])->toBe([])
        ->and($readiness['reasonCodes'])->toContain('uninitialized', 'terminal', 'supervisor_unassigned');

    [$zeroBooking, $zeroSession, $actor, $supervisor] = g2aCapabilityFixture();
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $zeroSession->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);
    expect(app(CleaningV2VisitExecutionCapabilityService::class)->readiness($zeroBooking, $zeroSession)['reasonCodes'])
        ->toContain('compensation_unconfigured');
});

it('keeps signed recurring hours as a candidate while capability stays blocked by current G1 eligibility', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'is_pricing_final' => true,
        'property_details' => ['_supervisor_pricing' => ['fee' => 100, 'hourlyRate' => 100]],
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->addDays(5)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'is_pricing_final' => true,
        'pricing_snapshot' => [
            'scheduleType' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculationMode' => 'hours',
            'hoursPerVisit' => 2,
            'requiredWorkers' => 2,
        ],
    ]);
    $actor = User::factory()->create();
    $supervisor = User::factory()->create(['is_active' => true]);
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 20000,
        'active_slot' => 1,
    ]);

    $readiness = app(CleaningV2VisitExecutionCapabilityService::class)->readiness($booking, $session);
    expect($readiness['executionCapability'])->toBeFalse()
        ->and($readiness['allowedActions'])->toBe([])
        ->and($readiness['taskCandidates'])->toBe([[
            'taskKey' => 'visit_scope',
            'sourceKind' => 'visit_scope',
            'requiredWorkers' => 2,
        ]])
        ->and($readiness['reasonCodes'])->toContain('unsupported_contract', 'task_worker_membership_unproven');
});
