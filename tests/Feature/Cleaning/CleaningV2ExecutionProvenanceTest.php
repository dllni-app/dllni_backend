<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoomPeriod;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Modules\Cleaning\Services\CleaningV2VisitExecutionProvenanceService;

function g2aProvenanceBooking(array $sessionOverrides = [], array $bookingOverrides = []): array
{
    $booking = CleaningBooking::factory()->create($bookingOverrides + [
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'is_pricing_final' => true,
        'base_price' => 80,
        'addons_total' => 20,
        'total_price' => 100,
    ]);
    $session = $booking->sessions()->create($sessionOverrides + [
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'base_price' => 80,
        'addons_total' => 20,
        'materials_total' => 0,
        'special_services_total' => 20,
        'total_price' => 100,
        'is_pricing_final' => true,
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'g2a.provenance.room',
        'client_key' => 'bedroom.1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Provenance room',
        'weight' => 1,
    ]);

    return [$booking, $session, $room];
}

function g2aApproveTimePeriodBooking(
    CleaningBooking $booking,
    CleaningBookingSession $session,
    array $roomSchedules,
    string $visitKey = 'signed.visit',
): void {
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
            'visitKey' => $visitKey,
            'estimatedDurationMinutes' => (int) round(((float) $session->duration_hours) * 60),
            'requiredSimultaneousWorkers' => $session->requiredWorkerCount(),
            'roomSchedules' => $roomSchedules,
        ]],
    ];
    $booking->forceFill(['property_details' => $details])->save();
}

function g2aApproveRoomPeriodBooking(
    CleaningBooking $booking,
    CleaningBookingSession $session,
    string $algorithm = 'room-period-v2-2026-10-14',
): void {
    $details = is_array($booking->property_details) ? $booking->property_details : [];
    $details['_room_period_v2_approved_total'] = (float) $booking->total_price;
    $details['_room_period_v2_quote_algorithm'] = $algorithm;
    $booking->forceFill(['property_details' => $details])->save();
    $session->forceFill([
        'pricing_snapshot' => [
            'contractVersion' => 'room_period_v2',
            'algorithmVersion' => $algorithm,
            'requiredSimultaneousWorkers' => $session->requiredWorkerCount(),
        ],
    ])->save();
}

function g2aRoomPeriodSnapshot(CleaningBookingSession $session, array $overrides = []): array
{
    return $overrides + [
        'clientKey' => 'bedroom.1',
        'visitSequence' => (int) $session->sequence,
        'roomType' => 'bedroom',
        'size' => 'small',
        'cleaningMode' => 'normal',
        'scheduledDate' => $session->scheduled_date->toDateString(),
        'scheduledTime' => '09:00',
        'numberOfWorkers' => 2,
        'estimatedWorkMinutes' => 240,
        'estimatedDurationMinutes' => 120,
        'basePrice' => 80,
        'specialServicesTotal' => 20,
        'specialServices' => [
            ['specialServiceId' => 37, 'totalPrice' => 20],
        ],
        'materials' => [],
    ];
}

it('derives stable room schedule and room period task candidates from exact visit links', function (): void {
    [$scheduleBooking, $scheduleSession, $scheduleRoom] = g2aProvenanceBooking([
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'signed.visit'],
    ]);
    $scheduleStart = CarbonImmutable::parse(
        $scheduleSession->scheduled_date->toDateString().' 09:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
    )->utc();
    g2aApproveTimePeriodBooking($scheduleBooking, $scheduleSession, [[
        'scheduleKey' => 'signed.schedule',
        'roomKey' => 'bedroom.1',
        'startAt' => $scheduleStart->setTimezone('Asia/Damascus')->toIso8601String(),
        'endAt' => $scheduleStart->addHours(2)->setTimezone('Asia/Damascus')->toIso8601String(),
        'numberOfWorkers' => 2,
        'estimatedWorkMinutes' => 240,
        'estimatedDurationMinutes' => 120,
    ]]);
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $scheduleBooking->id,
        'cleaning_booking_session_id' => $scheduleSession->id,
        'cleaning_booking_room_id' => $scheduleRoom->id,
        'schedule_key' => 'signed.schedule',
        'visit_key' => 'signed.visit',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => $scheduleStart,
        'ends_at' => $scheduleStart->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);

    $service = app(CleaningV2VisitExecutionProvenanceService::class);
    expect(data_get($scheduleBooking->fresh()->property_details, '_time_period_v2.visits.0.roomSchedules.0.visitKey'))
        ->toBeNull();
    $scheduleResult = $service->evaluate($scheduleBooking, $scheduleSession);
    expect($scheduleResult['valid'])->toBeTrue()
        ->and($scheduleResult['taskCandidates'])->toBe([[
            'taskKey' => 'schedule:signed.schedule',
            'sourceKind' => 'room_schedule',
            'requiredWorkers' => 2,
        ]]);

    [$periodBooking, $periodSession, $periodRoom] = g2aProvenanceBooking();
    $periodRoom->forceFill(['client_key' => null])->save();
    g2aApproveRoomPeriodBooking($periodBooking, $periodSession);
    CleaningBookingRoomPeriod::query()->create([
        'cleaning_booking_id' => $periodBooking->id,
        'cleaning_booking_session_id' => $periodSession->id,
        'cleaning_booking_room_id' => $periodRoom->id,
        'client_key' => 'bedroom.1',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'normal',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '09:00',
        'required_workers' => 2,
        'estimated_work_minutes' => 240,
        'estimated_duration_minutes' => 120,
        'base_price' => 80,
        'addons_total' => 20,
        'total_price' => 100,
        'pricing_snapshot' => g2aRoomPeriodSnapshot($periodSession),
    ]);
    $periodResult = $service->evaluate($periodBooking, $periodSession);
    expect($periodResult['valid'])->toBeTrue()
        ->and($periodResult['taskCandidates'])->toBe([[
            'taskKey' => 'room_period:bedroom.1',
            'sourceKind' => 'room_period',
            'requiredWorkers' => 2,
        ]]);

    $backfillSnapshot = $periodSession->pricing_snapshot;
    $backfillSnapshot['contractVersion'] = 'room_period_v2_backfill';
    $backfillSnapshot['approvedTotal'] = (float) $periodBooking->total_price;
    $periodSession->forceFill(['pricing_snapshot' => $backfillSnapshot])->save();
    expect($service->evaluate($periodBooking, $periodSession->fresh())['valid'])->toBeTrue();

    $periodSession->forceFill(['is_pricing_final' => false])->save();
    expect($service->evaluate($periodBooking, $periodSession->fresh())['reasonCodes'])
        ->toContain('unsigned_task_source');
});

it('rejects forged or tampered time period schedules without approved booking provenance', function (): void {
    [$unsignedBooking, $unsignedSession, $unsignedRoom] = g2aProvenanceBooking([
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'forged.visit'],
    ]);
    $unsignedStart = CarbonImmutable::parse(
        $unsignedSession->scheduled_date->toDateString().' 09:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
    )->utc();
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $unsignedBooking->id,
        'cleaning_booking_session_id' => $unsignedSession->id,
        'cleaning_booking_room_id' => $unsignedRoom->id,
        'schedule_key' => 'forged.schedule',
        'visit_key' => 'forged.visit',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => $unsignedStart,
        'ends_at' => $unsignedStart->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);

    $service = app(CleaningV2VisitExecutionProvenanceService::class);
    expect($service->evaluate($unsignedBooking, $unsignedSession)['reasonCodes'])
        ->toContain('unsigned_task_source');

    [$booking, $session, $room] = g2aProvenanceBooking([
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'approved.visit'],
    ]);
    $start = CarbonImmutable::parse(
        $session->scheduled_date->toDateString().' 09:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
    )->utc();
    $approvedSchedule = [
        'scheduleKey' => 'approved.schedule',
        'roomKey' => 'bedroom.1',
        'startAt' => $start->setTimezone('Asia/Damascus')->toIso8601String(),
        'endAt' => $start->addHours(2)->setTimezone('Asia/Damascus')->toIso8601String(),
        'numberOfWorkers' => 2,
        'estimatedWorkMinutes' => 240,
        'estimatedDurationMinutes' => 120,
    ];
    g2aApproveTimePeriodBooking($booking, $session, [$approvedSchedule], 'approved.visit');
    $persisted = CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'approved.schedule',
        'visit_key' => 'approved.visit',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => $start,
        'ends_at' => $start->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);
    expect($service->evaluate($booking, $session)['valid'])->toBeTrue();

    $persisted->forceFill(['work_minutes' => 239])->save();
    expect($service->evaluate($booking, $session)['reasonCodes'])
        ->toContain('invalid_task_source');
    $persisted->forceFill(['work_minutes' => 240])->save();

    $extraRoom = $booking->rooms()->create([
        'room_key' => 'g2a.provenance.room.extra',
        'client_key' => 'bedroom.2',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Extra forged room',
        'weight' => 1,
    ]);
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $extraRoom->id,
        'schedule_key' => 'forged.extra',
        'visit_key' => 'approved.visit',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => $start,
        'ends_at' => $start->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);
    expect($service->evaluate($booking, $session)['reasonCodes'])
        ->toContain('invalid_task_source');
});

it('rejects room period algorithm schedule and duplicate-room provenance mismatches', function (): void {
    [$booking, $session, $room] = g2aProvenanceBooking();
    $room->forceFill(['client_key' => null])->save();
    g2aApproveRoomPeriodBooking($booking, $session);
    $period = CleaningBookingRoomPeriod::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'client_key' => 'bedroom.1',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'normal',
        'scheduled_date' => $session->scheduled_date->toDateString(),
        'scheduled_time' => '09:00',
        'required_workers' => 2,
        'estimated_work_minutes' => 240,
        'estimated_duration_minutes' => 120,
        'base_price' => 80,
        'addons_total' => 20,
        'total_price' => 100,
        'pricing_snapshot' => g2aRoomPeriodSnapshot($session),
    ]);
    $service = app(CleaningV2VisitExecutionProvenanceService::class);
    expect($service->evaluate($booking, $session)['valid'])->toBeTrue();

    $session->forceFill(['pricing_snapshot' => [
        'contractVersion' => 'room_period_v2',
        'algorithmVersion' => 'forged-algorithm',
        'requiredSimultaneousWorkers' => 2,
    ]])->save();
    expect($service->evaluate($booking, $session->fresh())['reasonCodes'])
        ->toContain('invalid_task_source');
    g2aApproveRoomPeriodBooking($booking, $session);

    $tamperedSnapshot = $period->pricing_snapshot;
    $tamperedSnapshot['scheduledDate'] = now()->addDays(9)->toDateString();
    $period->forceFill(['pricing_snapshot' => $tamperedSnapshot])->save();
    expect($service->evaluate($booking, $session->fresh())['reasonCodes'])
        ->toContain('invalid_task_source');
    $period->forceFill(['pricing_snapshot' => g2aRoomPeriodSnapshot($session)])->save();

    $tamperedSnapshot = $period->pricing_snapshot;
    $tamperedSnapshot['specialServices'][0]['totalPrice'] = 19;
    $period->forceFill(['pricing_snapshot' => $tamperedSnapshot])->save();
    expect($service->evaluate($booking, $session->fresh())['reasonCodes'])
        ->toContain('invalid_task_source');
    $period->forceFill(['pricing_snapshot' => g2aRoomPeriodSnapshot($session)])->save();

    $session->forceFill(['special_services_total' => 19])->save();
    expect($service->evaluate($booking, $session->fresh())['reasonCodes'])
        ->toContain('invalid_task_source');
    $session->forceFill(['special_services_total' => 20])->save();

    $secondRoom = $booking->rooms()->create([
        'room_key' => 'g2a.provenance.room.second',
        'client_key' => null,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Second period room',
        'weight' => 1,
    ]);
    expect(fn () => CleaningBookingRoomPeriod::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $secondRoom->id,
        'client_key' => 'bedroom.1',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'normal',
        'scheduled_date' => $session->scheduled_date->toDateString(),
        'scheduled_time' => '09:00',
        'required_workers' => 2,
        'estimated_work_minutes' => 240,
        'estimated_duration_minutes' => 120,
        'base_price' => 80,
        'addons_total' => 20,
        'total_price' => 100,
        'pricing_snapshot' => g2aRoomPeriodSnapshot($session),
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect($service->evaluate($booking, $session->fresh())['reasonCodes'])
        ->toContain('invalid_task_source');
});

it('recognizes signed recurring hours and open time only as visit scope candidates', function (): void {
    [$recurringBooking, $recurringSession] = g2aProvenanceBooking([
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'hours',
        'duration_hours' => 2,
        'pricing_snapshot' => [
            'scheduleType' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculationMode' => 'hours',
            'hoursPerVisit' => 2,
            'requiredWorkers' => 2,
        ],
    ]);
    $service = app(CleaningV2VisitExecutionProvenanceService::class);
    $recurring = $service->evaluate($recurringBooking, $recurringSession);
    expect($recurring['valid'])->toBeTrue()
        ->and($recurring['taskCandidates'])->toBe([[
            'taskKey' => 'visit_scope',
            'sourceKind' => 'visit_scope',
            'requiredWorkers' => 2,
        ]]);

    [$openBooking, $openSession] = g2aProvenanceBooking([
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'actual_time',
        'duration_hours' => 2,
        'open_time_expected_max_minutes' => 120,
        'pricing_snapshot' => [
            'scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
            'workerCount' => 2,
            'expectedMaxMinutes' => 120,
        ],
    ], [
        'booking_kind' => 'open_time',
    ]);
    $open = $service->evaluate($openBooking, $openSession);
    expect($open['valid'])->toBeTrue()
        ->and($open['taskCandidates'])->toBe([[
            'taskKey' => 'visit_scope',
            'sourceKind' => 'visit_scope',
            'requiredWorkers' => 2,
        ]]);
});

it('fails closed on unsigned ambiguous and cross-booking task provenance', function (): void {
    [$unsignedBooking, $unsignedSession] = g2aProvenanceBooking([
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'hours',
        'pricing_snapshot' => null,
    ]);
    $service = app(CleaningV2VisitExecutionProvenanceService::class);
    expect($service->evaluate($unsignedBooking, $unsignedSession)['reasonCodes'])->toContain('unsigned_task_source');

    [$booking, $session, $room] = g2aProvenanceBooking([
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'ambiguous.visit'],
    ]);
    $ambiguousStart = CarbonImmutable::parse(
        $session->scheduled_date->toDateString().' 09:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
    )->utc();
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'ambiguous.schedule',
        'visit_key' => 'ambiguous.visit',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => $ambiguousStart,
        'ends_at' => $ambiguousStart->addHour(),
        'required_workers' => 1,
        'work_minutes' => 60,
        'duration_minutes' => 60,
    ]);
    CleaningBookingRoomPeriod::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'client_key' => 'ambiguous.room',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'normal',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '09:00',
        'required_workers' => 1,
        'estimated_work_minutes' => 60,
        'estimated_duration_minutes' => 60,
    ]);
    expect($service->evaluate($booking, $session)['reasonCodes'])->toContain('ambiguous_task_source');

    [$otherBooking, , $otherRoom] = g2aProvenanceBooking();
    [$crossBooking, $crossSession] = g2aProvenanceBooking([
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'cross.visit'],
    ]);
    $crossStart = CarbonImmutable::parse(
        $crossSession->scheduled_date->toDateString().' 09:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
    )->utc();
    g2aApproveTimePeriodBooking($crossBooking, $crossSession, [[
        'scheduleKey' => 'cross.schedule',
        'roomKey' => 'bedroom.1',
        'startAt' => $crossStart->setTimezone('Asia/Damascus')->toIso8601String(),
        'endAt' => $crossStart->addHour()->setTimezone('Asia/Damascus')->toIso8601String(),
        'numberOfWorkers' => 1,
        'estimatedWorkMinutes' => 60,
        'estimatedDurationMinutes' => 60,
    ]], 'cross.visit');
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $crossBooking->id,
        'cleaning_booking_session_id' => $crossSession->id,
        'cleaning_booking_room_id' => $otherRoom->id,
        'schedule_key' => 'cross.schedule',
        'visit_key' => 'cross.visit',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => $crossStart,
        'ends_at' => $crossStart->addHour(),
        'required_workers' => 1,
        'work_minutes' => 60,
        'duration_minutes' => 60,
    ]);
    expect($otherBooking->id)->not->toBe($crossBooking->id)
        ->and($service->evaluate($crossBooking, $crossSession)['reasonCodes'])->toContain('invalid_task_source');
});

it('rejects legacy supervised team and terminal visits without emitting task candidates', function (): void {
    [$legacyBooking, $legacySession] = g2aProvenanceBooking([], [
        'booking_kind' => 'supervised_team',
    ]);
    $service = app(CleaningV2VisitExecutionProvenanceService::class);
    $legacy = $service->evaluate($legacyBooking, $legacySession);
    expect($legacy['valid'])->toBeFalse()
        ->and($legacy['reasonCodes'])->toContain('unsupported_contract')
        ->and($legacy['taskCandidates'])->toBe([]);

    [$terminalBooking, $terminalSession] = g2aProvenanceBooking([
        'status' => 'completed',
    ]);
    $terminal = $service->evaluate($terminalBooking, $terminalSession);
    expect($terminal['valid'])->toBeFalse()
        ->and($terminal['reasonCodes'])->toContain('terminal')
        ->and($terminal['taskCandidates'])->toBe([]);
});
