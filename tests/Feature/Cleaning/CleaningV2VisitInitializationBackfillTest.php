<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Http\Middleware\RequireSessionScopedCleaningLifecycle;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingVisitInitializationService;

function createB1RoomPeriodBackfillBooking(array $overrides = []): CleaningBooking
{
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'total_price' => 100,
        'property_details' => [
            '_room_period_v2_approved_total' => 140.0,
            '_room_period_v2_quote_algorithm' => 'room-period-v2-2026-10-14',
        ],
        'base_price' => 100,
        'addons_total' => 20,
        'travel_fee' => 10,
        'admin_margin_amount' => 10,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 140,
        ...$overrides,
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'bedroom.small.1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Bedroom',
        'weight' => 1,
    ]);
    $booking->roomPeriods()->create([
        'cleaning_booking_room_id' => $room->id,
        'client_key' => 'bedroom.1',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'regular',
        'scheduled_date' => now(config('app.timezone'))->addDays(3)->toDateString(),
        'scheduled_time' => '09:00',
        'required_workers' => 2,
        'estimated_work_minutes' => 120,
        'estimated_duration_minutes' => 60,
        'materials' => ['providedByPlatform' => false, 'materialTypeIds' => []],
        'special_services' => [],
        'base_price' => 100,
        'addons_total' => 20,
        'total_price' => 120,
        'pricing_snapshot' => [
            'specialServicesTotal' => 5,
            'specialServices' => [
                ['specialServiceId' => 37, 'totalPrice' => 5],
            ],
            'materials' => [
                ['materialTypeId' => 91, 'totalPrice' => 15],
            ],
        ],
    ]);

    return $booking->fresh();
}

it('previews then idempotently initializes a safe room-period booking', function (): void {
    $booking = createB1RoomPeriodBackfillBooking();
    $service = app(CleaningBookingVisitInitializationService::class);

    $preview = $service->initializeBooking($booking->id);
    expect($preview['status'])->toBe('would_initialize')
        ->and($booking->sessions()->count())->toBe(0);

    $applied = $service->initializeBooking($booking->id, true);
    $booking->refresh();
    $session = $booking->sessions()->sole();

    expect($applied['status'])->toBe('initialized')
        ->and($session->supervision_mode)->toBe(CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)
        ->and($session->supervision_initialized_at)->not->toBeNull()
        ->and((float) $session->base_price)->toBe(100.0)
        ->and((float) $session->addons_total)->toBe(20.0)
        ->and((float) $session->travel_fee)->toBe(10.0)
        ->and((float) $session->admin_margin_amount)->toBe(10.0)
        ->and((float) $session->total_price)->toBe(140.0)
        ->and((int) $booking->roomPeriods()->value('cleaning_booking_session_id'))->toBe((int) $session->id);

    $again = $service->initializeBooking($booking->id, true);
    expect($again['status'])->toBe('unchanged')
        ->and($booking->sessions()->count())->toBe(1)
        ->and($booking->sessions()->sole()->id)->toBe($session->id);
});

it('links and initializes only an exactly reconciled preexisting room-period session', function (): void {
    $booking = createB1RoomPeriodBackfillBooking();
    $period = $booking->roomPeriods()->sole();
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => $period->scheduled_date->toDateString(),
        'scheduled_time' => mb_substr((string) $period->scheduled_time, 0, 5),
        'duration_hours' => 1,
        'required_workers' => 2,
        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
        'status' => CleaningBookingSessionStatus::Scheduled,
        'base_price' => 100,
        'addons_total' => 20,
        'materials_total' => 15,
        'special_services_total' => 5,
        'travel_fee' => 10,
        'admin_margin_amount' => 10,
        'extension_fee_total' => 0,
        'total_price' => 140,
    ]);

    $result = app(CleaningBookingVisitInitializationService::class)
        ->initializeBooking($booking->id, true);

    expect($result['status'])->toBe('initialized')
        ->and($booking->sessions()->sole()->id)->toBe($session->id)
        ->and($session->fresh()->supervision_mode)->toBe(CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)
        ->and((int) $booking->roomPeriods()->sole()->cleaning_booking_session_id)->toBe((int) $session->id);
});

it('skips a preexisting room-period session when any signed envelope or parent component differs', function (): void {
    $cases = [
        'date' => ['scheduled_date', now(config('app.timezone'))->addDays(9)->toDateString()],
        'time' => ['scheduled_time', '12:00'],
        'duration' => ['duration_hours', 1.5],
        'workers' => ['required_workers', 3],
        'base' => ['base_price', 101],
        'addons' => ['addons_total', 21],
        'materials' => ['materials_total', 14],
        'specials' => ['special_services_total', 6],
        'travel' => ['travel_fee', 11],
        'admin' => ['admin_margin_amount', 11],
        'extension' => ['extension_fee_total', 1],
        'total' => ['total_price', 141],
        'status' => ['status', CleaningBookingSessionStatus::WorkerAssigned],
    ];

    foreach ($cases as [$field, $value]) {
        $booking = createB1RoomPeriodBackfillBooking();
        $period = $booking->roomPeriods()->sole();
        $attributes = [
            'sequence' => 1,
            'calculation_mode' => 'task',
            'scheduled_date' => $period->scheduled_date->toDateString(),
            'scheduled_time' => mb_substr((string) $period->scheduled_time, 0, 5),
            'duration_hours' => 1,
            'required_workers' => 2,
            'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
            'status' => CleaningBookingSessionStatus::Scheduled,
            'base_price' => 100,
            'addons_total' => 20,
            'materials_total' => 15,
            'special_services_total' => 5,
            'travel_fee' => 10,
            'admin_margin_amount' => 10,
            'extension_fee_total' => 0,
            'total_price' => 140,
        ];
        $attributes[$field] = $value;
        $session = $booking->sessions()->create($attributes);

        $result = app(CleaningBookingVisitInitializationService::class)
            ->initializeBooking($booking->id, true);

        expect($result['status'])->toBe('skipped')
            ->and($result['reason'])->toBe('room_period_session_provenance_mismatch')
            ->and($session->fresh()->supervision_mode)->toBeNull()
            ->and($session->fresh()->supervision_initialized_at)->toBeNull()
            ->and($booking->roomPeriods()->sole()->cleaning_booking_session_id)->toBeNull();
    }
});

it('preserves existing signed zero-fee V2 session identity while initializing supervision metadata', function (): void {
    $date = now(config('app.timezone'))->addDays(4)->toDateString();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'total_price' => 100,
        'property_details' => [
            '_room_period_v2_approved_total' => 100,
            '_supervisor_pricing' => [
                'hourlyRate' => 0,
                'billableMinutes' => 120,
                'fee' => 0,
            ],
            '_time_period_v2' => [
                'contractVersion' => 2,
                'requiredSimultaneousWorkers' => 2,
                'reconciliationRequired' => false,
                'pricing' => [
                    'isPricingFinal' => true,
                    'totalPrice' => 100,
                    'supervisor' => [
                        'hourlyRate' => 0,
                        'billableMinutes' => 120,
                        'fee' => 0,
                    ],
                ],
                'visits' => [[
                    'sequence' => 1,
                    'startAt' => $date.'T10:00:00+03:00',
                    'estimatedDurationMinutes' => 120,
                    'requiredSimultaneousWorkers' => 2,
                ]],
            ],
        ],
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $date,
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
        'status' => CleaningBookingSessionStatus::Scheduled,
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'visit.1'],
    ]);

    $result = app(CleaningBookingVisitInitializationService::class)
        ->initializeBooking($booking->id, true);

    expect($result['status'])->toBe('initialized')
        ->and($booking->sessions()->sole()->id)->toBe($session->id)
        ->and($booking->sessions()->sole()->supervision_mode)
        ->toBe(CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR);
});

it('does not infer supervisor ownership for unsigned historical recurring or open-time groups', function (string $sessionType): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => $sessionType,
        'calculation_mode' => $sessionType === CleaningBookingSession::TYPE_OPEN_TIME ? 'actual_time' : 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(4)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
        'status' => CleaningBookingSessionStatus::Scheduled,
    ]);

    $result = app(CleaningBookingVisitInitializationService::class)
        ->initializeBooking($booking->id, true);

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('unproven_v2_group_supervision')
        ->and($session->fresh()->supervision_mode)->toBeNull()
        ->and($session->fresh()->supervision_initialized_at)->toBeNull();
})->with([
    'recurring' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
    'open-time' => CleaningBookingSession::TYPE_OPEN_TIME,
]);

it('skips started accepted and custody-bearing bookings without mutation', function (): void {
    $service = app(CleaningBookingVisitInitializationService::class);

    $started = createB1RoomPeriodBackfillBooking(['work_started_at' => now()]);
    expect($service->initializeBooking($started->id, true)['reason'])
        ->toBe('attendance_or_work_requires_reconciliation')
        ->and($started->sessions()->count())->toBe(0);

    $accepted = createB1RoomPeriodBackfillBooking();
    $accepted->workerAssignments()->create([
        'worker_id' => App\Models\Worker::factory()->create()->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    expect($service->initializeBooking($accepted->id, true)['reason'])
        ->toBe('attendance_or_work_requires_reconciliation')
        ->and($accepted->sessions()->count())->toBe(0);

    $custody = createB1RoomPeriodBackfillBooking();
    $custody->materialKit()->create([
        'status' => 'received',
        'received_at' => now(),
    ]);
    expect($service->initializeBooking($custody->id, true)['reason'])
        ->toBe('custody_requires_reconciliation')
        ->and($custody->sessions()->count())->toBe(0);
});

it('fails closed when signed room-period financial or schedule provenance is ambiguous', function (): void {
    $service = app(CleaningBookingVisitInitializationService::class);

    $emptySnapshot = createB1RoomPeriodBackfillBooking();
    $emptySnapshot->roomPeriods()->update(['pricing_snapshot' => []]);
    expect($service->initializeBooking($emptySnapshot->id, true)['reason'])
        ->toBe('unproven_room_period_financial_provenance')
        ->and($emptySnapshot->sessions()->count())->toBe(0);

    $mismatchedComponents = createB1RoomPeriodBackfillBooking();
    $mismatchedComponents->roomPeriods()->update([
        'pricing_snapshot' => [
            'specialServicesTotal' => 5,
            'specialServices' => [
                ['specialServiceId' => 37, 'totalPrice' => 5],
            ],
            'materials' => [['materialTypeId' => 91, 'totalPrice' => 10]],
        ],
    ]);
    expect($service->initializeBooking($mismatchedComponents->id, true)['reason'])
        ->toBe('unproven_room_period_financial_provenance')
        ->and($mismatchedComponents->sessions()->count())->toBe(0);

    $invalidDuration = createB1RoomPeriodBackfillBooking();
    $invalidDuration->roomPeriods()->update(['estimated_duration_minutes' => 0]);
    expect($service->initializeBooking($invalidDuration->id, true)['reason'])
        ->toBe('invalid_room_period_schedule')
        ->and($invalidDuration->sessions()->count())->toBe(0);
});

it('skips terminal and accepted-status history even when lifecycle timestamps are inconsistent', function (): void {
    $service = app(CleaningBookingVisitInitializationService::class);

    $terminal = createB1RoomPeriodBackfillBooking(['status' => 'completed']);
    expect($service->initializeBooking($terminal->id, true)['reason'])
        ->toBe('terminal_booking_requires_reconciliation')
        ->and($terminal->sessions()->count())->toBe(0);

    $acceptedWithoutTimestamp = createB1RoomPeriodBackfillBooking();
    $acceptedWithoutTimestamp->workerAssignments()->create([
        'worker_id' => App\Models\Worker::factory()->create()->id,
        'status' => 'accepted',
        'accepted_at' => null,
    ]);
    expect($service->initializeBooking($acceptedWithoutTimestamp->id, true)['reason'])
        ->toBe('attendance_or_work_requires_reconciliation')
        ->and($acceptedWithoutTimestamp->sessions()->count())->toBe(0);
});

it('keeps the command dry by default and requires explicit ids for apply', function (): void {
    $booking = createB1RoomPeriodBackfillBooking();

    $this->artisan('cleaning:initialize-v2-visits', [
        '--booking-ids' => [$booking->id],
    ])->assertSuccessful();
    expect($booking->sessions()->count())->toBe(0);

    $this->artisan('cleaning:initialize-v2-visits', [
        '--apply' => true,
    ])->assertFailed();
    expect($booking->sessions()->count())->toBe(0);

    $this->artisan('cleaning:initialize-v2-visits', [
        '--booking-ids' => [$booking->id],
        '--apply' => true,
    ])->assertSuccessful();
    expect($booking->sessions()->count())->toBe(1);
});

it('fails closed for V2 execution while leaving attendance and sos paths independent', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
    ]);
    $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $middleware = app(RequireSessionScopedCleaningLifecycle::class);
    $next = static fn (): Illuminate\Http\JsonResponse => response()->json(['passed' => true]);

    $requestFor = static function (string $action) use ($booking): Request {
        $request = Request::create(
            "/api/v1/cleaning-bookings/{$booking->id}/{$action}",
            'POST',
        );
        $request->setRouteResolver(static fn (): object => new class($booking)
        {
            public function __construct(private readonly CleaningBooking $booking) {}

            public function parameter(string $key, mixed $default = null): mixed
            {
                return $key === 'cleaning_booking' ? $this->booking : $default;
            }
        });

        return $request;
    };

    $blocked = $middleware->handle($requestFor('start-work'), $next);
    expect($blocked->getStatusCode())->toBe(422)
        ->and($blocked->getData(true)['code'])->toBe('CLEANING_V2_VISIT_EXECUTION_UNAVAILABLE');

    foreach (['start-travel', 'arrive', 'sos'] as $attendanceAction) {
        $allowed = $middleware->handle($requestFor($attendanceAction), $next);
        expect($allowed->getStatusCode())->toBe(200)
            ->and($allowed->getData(true)['passed'])->toBeTrue();
    }
});
