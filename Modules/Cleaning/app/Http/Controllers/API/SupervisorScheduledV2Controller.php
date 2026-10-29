<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoomPeriod;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

/** Read-only supervisor visibility. It never writes visit attendance or room completion. */
final class SupervisorScheduledV2Controller
{
    public function __construct(private readonly SupervisorScheduledV2Service $scheduled) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->scheduled->assignedQuery($request->user(), $data['date'] ?? null);

        $page = $query->orderBy('scheduled_date')->orderBy('id')->paginate((int) ($data['perPage'] ?? 15));

        return response()->json([
            'data' => $page->getCollection()
                ->filter(fn (CleaningBooking $booking): bool => $this->scheduled->isEligible($booking))
                ->map(fn (CleaningBooking $booking): array => $this->header($booking, $request->user()))->values()->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $booking): JsonResponse
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);

        return response()->json(['data' => $this->detail($order, $request->user())]);
    }

    public function visit(Request $request, int $booking, int $visit): JsonResponse
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $matched = collect($this->visits($order, $request->user()))->firstWhere('sequence', $visit);
        abort_unless($matched !== null, 404);

        return response()->json(['data' => $matched]);
    }

    public function unsupportedTransition(Request $request, int $booking, int $visit): JsonResponse
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        abort_unless(collect($this->visits($order, $request->user()))->contains('sequence', $visit), 404);

        return response()->json([
            'code' => 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE',
            'message' => 'تنفيذ زيارات الحجز وإثبات الحضور وتسليم المواد يحتاج مسار جلسات معتمد.',
        ], 422);
    }

    private function header(CleaningBooking $booking, \App\Models\User $user): array
    {
        $ownedSessions = $booking->sessions()->exists()
            ? $this->scheduled->assignedSessions($user, $booking)
                ->orderBy('scheduled_date')
                ->orderBy('scheduled_time')
                ->orderBy('sequence')
                ->get()
            : collect();

        return [
            'id' => $booking->id,
            'bookingNumber' => $booking->booking_number,
            'serviceType' => 'standard',
            'status' => $booking->status?->value ?? (string) $booking->status,
            'supervisionMode' => 'schedule_read_only',
            'scheduledDate' => $ownedSessions->isNotEmpty()
                ? $ownedSessions->first()->scheduled_date?->toDateString()
                : $booking->scheduled_date?->toDateString(),
            'visitCount' => $booking->sessions()->exists()
                ? $ownedSessions->count()
                : $booking->roomPeriods()->distinct()->count('visit_sequence'),
            'allowedActions' => [],
        ];
    }

    private function detail(CleaningBooking $booking, \App\Models\User $user): array
    {
        return $this->header($booking, $user) + [
            'visits' => $this->visits($booking, $user),
            'serverNow' => now()->toIso8601String(),
            'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        ];
    }

    private function visits(CleaningBooking $booking, \App\Models\User $user): array
    {
        if ($booking->sessions()->exists()) {
            $sessions = $this->scheduled->assignedSessions($user, $booking)
                ->with(['workerAssignments.worker.user', 'activeSupervisorAssignment', 'supervisorAttendances'])
                ->get();
            $schedules = $booking->roomSchedules()->with('room')->get()->groupBy('cleaning_booking_session_id');
            $roomPeriods = $booking->roomPeriods()->with('room')->get()->groupBy('cleaning_booking_session_id');
            $materialsBySession = DB::table('cleaning_time_periods')
                ->where('cleaning_booking_id', $booking->id)
                ->get(['cleaning_booking_session_id', 'period_key', 'materials_choice'])
                ->groupBy('cleaning_booking_session_id');
            $materialsBySchedule = DB::table('cleaning_time_period_room_schedules as link')
                ->join('cleaning_time_periods as period', 'period.id', '=', 'link.cleaning_time_period_id')
                ->where('period.cleaning_booking_id', $booking->id)
                ->get(['link.cleaning_room_schedule_id', 'period.period_key', 'period.materials_choice'])
                ->groupBy('cleaning_room_schedule_id');

            return $sessions->map(function (CleaningBookingSession $session) use ($schedules, $roomPeriods, $materialsBySession, $materialsBySchedule): array {
                $accepted = $session->workerAssignments->filter(
                    fn (CleaningBookingSessionWorkerAssignment $worker): bool => $worker->isAccepted()
                );
                $arrived = $accepted->filter(
                    fn (CleaningBookingSessionWorkerAssignment $worker): bool => $worker->arrived_at !== null
                );
                $attending = $accepted->filter(
                    fn (CleaningBookingSessionWorkerAssignment $worker): bool => $worker->work_started_at !== null
                );
                $required = $session->requiredWorkerCount();
                $actualAttendanceCertified = $session->activeSupervisorAssignment !== null
                    && app(\Modules\Cleaning\Services\CleaningV2VisitWorkerAttestationService::class)
                        ->allPresent($session, $session->activeSupervisorAssignment->id);
                $noShowWorkers = $session->workerAttestations()->where('outcome', 'no_show')->count();
                $supervisorAssignment = $session->activeSupervisorAssignment;
                $supervisorAttendance = $session->supervisorAttendances->first(
                    fn ($record): bool => $supervisorAssignment !== null
                        && (int) $record->cleaning_booking_session_supervisor_assignment_id === (int) $supervisorAssignment->id,
                );
                $attendanceActions = [];
                if ($session->isSupervisionInitialized() && $supervisorAssignment !== null && ! $session->isTerminal()) {
                    if ($supervisorAttendance?->started_travel_at === null) {
                        $attendanceActions[] = 'start_travel';
                    } elseif ($supervisorAttendance->arrived_at === null) {
                        $attendanceActions[] = 'arrive';
                    }
                }
                $visitSchedules = $schedules->get($session->id, collect());
                $visitRoomPeriods = $roomPeriods->get($session->id, collect());
                $interval = $this->canonicalVisitInterval($session, $visitSchedules, $visitRoomPeriods);

                return [
                    'id' => $session->id,
                    'sequence' => $session->sequence,
                    'status' => $session->status?->value ?? (string) $session->status,
                    'scheduledStartAt' => $this->isoUtc($interval[0] ?? null),
                    'scheduledEndAt' => $this->isoUtc($interval[1] ?? null),
                    'supervisionMode' => $session->supervision_mode,
                    'initializationState' => $session->isSupervisionInitialized() ? 'initialized' : 'uninitialized',
                    'workStartedAt' => $session->work_started_at?->toIso8601String(),
                    'workFinishedAt' => $session->work_finished_at?->toIso8601String(),
                    'attendance' => [
                        'source' => 'session', 'requiredWorkers' => $required,
                        'acceptedWorkers' => $accepted->count(), 'arrivedWorkers' => $arrived->count(),
                        'plannedWorkers' => $required, 'workStartedWorkers' => $attending->count(),
                        'actualWorkers' => $actualAttendanceCertified ? $required : null,
                        'actualAttendanceCertified' => $actualAttendanceCertified,
                        'noShowWorkers' => $noShowWorkers,
                        'teamReady' => $arrived->count() >= $required,
                    ],
                    // Provenance owned by the visit supervisor, not inferred
                    // from any worker's arrival or booking-level start state.
                    'supervisorAttendance' => [
                        'startedTravelAt' => $supervisorAttendance?->started_travel_at?->toIso8601String(),
                        'arrivedAt' => $supervisorAttendance?->arrived_at?->toIso8601String(),
                        'allowedActions' => $attendanceActions,
                    ],
                    'financials' => $this->visitFinancials($session, $accepted, $interval),
                    'materials' => $this->materialsSources($materialsBySession->get($session->id, collect())),
                    // Exposed only through the assigned-supervisor V2 routes,
                    // never through an individual worker's booking resource.
                    'materialsTotal' => $session->materials_total === null
                        ? null : round((float) $session->materials_total, 2),
                    'rooms' => $visitSchedules->map(fn (CleaningRoomSchedule $schedule): array => [
                        'roomId' => $schedule->cleaning_booking_room_id,
                        'roomKey' => $schedule->room?->client_key ?? $schedule->room?->room_key,
                        'roomType' => $schedule->room?->room_type,
                        'scheduleKey' => $schedule->schedule_key,
                        'startsAt' => $this->isoUtc($this->roomScheduleInstant($schedule->getRawOriginal('starts_at'))),
                        'endsAt' => $this->isoUtc($this->roomScheduleInstant($schedule->getRawOriginal('ends_at'))),
                        'requiredWorkers' => $schedule->required_workers,
                        'materials' => $this->materialsSources($materialsBySchedule->get($schedule->id, collect())),
                        'executionStatus' => 'not_tracked_per_room',
                    ])->concat($visitRoomPeriods->map(fn (CleaningBookingRoomPeriod $period): array => [
                        'roomId' => $period->cleaning_booking_room_id,
                        'roomKey' => $period->client_key,
                        'roomType' => $period->room_type,
                        'roomSize' => $period->room_size,
                        'cleaningMode' => $period->cleaning_mode,
                        'scheduledDate' => $period->scheduled_date?->toDateString(),
                        'scheduledTime' => mb_substr((string) $period->scheduled_time, 0, 5),
                        'requiredWorkers' => $period->required_workers,
                        'materials' => $this->roomPeriodMaterials($period),
                        'executionStatus' => 'not_tracked_per_room',
                    ]))->values()->all(),
                    'allowedActions' => [],
                ];
            })->all();
        }

        // Room-period contract has no session rows or per-visit attendance ledger.
        // Its booking-level worker arrivals must never be presented as visit attendance.
        return $booking->roomPeriods()->with('room.assignedWorker.user')->get()->groupBy('visit_sequence')
            ->map(function (Collection $periods, $sequence): array {
                $interval = $this->roomPeriodInterval($periods);

                return [
                    'id' => null, 'sequence' => (int) $sequence, 'status' => 'not_session_tracked',
                    'scheduledStartAt' => $this->isoUtc($interval[0] ?? null),
                    'scheduledEndAt' => $this->isoUtc($interval[1] ?? null),
                    'attendance' => [
                        'source' => 'unavailable', 'requiredWorkers' => null,
                        'acceptedWorkers' => null, 'arrivedWorkers' => null, 'teamReady' => null,
                        'plannedWorkers' => null, 'workStartedWorkers' => null,
                        'actualWorkers' => null, 'actualAttendanceCertified' => false,
                    ],
                    'financials' => null,
                    'materials' => ['source' => null, 'periods' => []],
                    'rooms' => $periods->map(fn ($period): array => [
                        'roomId' => $period->cleaning_booking_room_id,
                        'roomKey' => $period->client_key,
                        'roomType' => $period->room_type,
                        'roomSize' => $period->room_size,
                        'cleaningMode' => $period->cleaning_mode,
                        'scheduledDate' => $period->scheduled_date?->toDateString(),
                        'scheduledTime' => mb_substr((string) $period->scheduled_time, 0, 5),
                        'requiredWorkers' => $period->required_workers,
                        'materials' => $this->roomPeriodMaterials($period),
                        'executionStatus' => 'not_tracked_per_room',
                    ])->values()->all(),
                    'allowedActions' => [],
                ];
            })->values()->all();
    }

    private function materialsSources(Collection $periods): array
    {
        $sources = $periods->pluck('materials_choice')->unique()->values();

        return [
            'source' => $sources->count() === 1 && in_array($sources->first(), ['customer_all', 'customer_partial', 'platform_all'], true)
                ? $sources->first() : null,
            'periods' => $periods->map(static fn ($period): array => [
                'periodKey' => $period->period_key,
                'source' => in_array($period->materials_choice, ['customer_all', 'customer_partial', 'platform_all'], true)
                    ? $period->materials_choice : null,
            ])->values()->all(),
        ];
    }

    private function roomPeriodMaterials(CleaningBookingRoomPeriod $period): array
    {
        $materials = $period->materials;
        $provided = is_array($materials) ? ($materials['providedByPlatform'] ?? null) : null;

        return [
            'source' => is_bool($provided) ? ($provided ? 'platform_all' : 'customer_all') : null,
            'providedByPlatform' => is_bool($provided) ? $provided : null,
            'materialTypeIds' => is_array($materials) && is_array($materials['materialTypeIds'] ?? null)
                ? array_values(array_map('intval', $materials['materialTypeIds'])) : [],
        ];
    }

    private function visitFinancials(CleaningBookingSession $session, Collection $accepted, ?array $interval): array
    {
        $money = static fn (mixed $value): ?float => $value === null ? null : round((float) $value, 2);
        $supervisorRate = $session->activeSupervisorAssignment?->compensation_hourly_rate;
        $scheduledMinutes = $interval === null ? null : max(0, (int) ceil($interval[0]->diffInSeconds($interval[1]) / 60));
        $supervisorShare = $supervisorRate === null || $scheduledMinutes === null
            ? null : round(max(0.0, (float) $supervisorRate) * $scheduledMinutes / 60, 2);

        return [
            'status' => 'projected',
            'isFinal' => false,
            'isPricingFinal' => (bool) $session->is_pricing_final,
            'currency' => (string) ($session->pricing_snapshot['currency'] ?? config('app.currency', 'SYP')),
            'baseServiceTotal' => $money($session->getRawOriginal('base_price')),
            'specialServicesTotal' => $money($session->getRawOriginal('special_services_total')),
            'materialsTotal' => $money($session->getRawOriginal('materials_total')),
            'addonsTotal' => $money($session->getRawOriginal('addons_total')),
            'travelTotal' => $money($session->getRawOriginal('travel_fee')),
            'adminMarginTotal' => $money($session->getRawOriginal('admin_margin_amount')),
            'totalPrice' => $money($session->getRawOriginal('total_price')),
            'supervisorHourlyRate' => $money($supervisorRate),
            'supervisorShareAmount' => $supervisorShare,
            'supervisorScheduledMinutes' => $scheduledMinutes,
            'supervisorCompensationStatus' => $supervisorRate === null ? 'unassigned' : ($scheduledMinutes === null ? 'schedule_unavailable' : 'projected'),
            'supervisorTravelAmount' => null,
            'plannedWorkerCount' => $session->requiredWorkerCount(),
            'actualWorkerCount' => null,
            'actualAttendanceCertified' => $session->activeSupervisorAssignment !== null
                && app(\Modules\Cleaning\Services\CleaningV2VisitWorkerAttestationService::class)
                    ->allPresent($session, $session->activeSupervisorAssignment->id),
            'workers' => $accepted->map(static fn (CleaningBookingSessionWorkerAssignment $assignment): array => [
                'workerId' => $assignment->worker_id,
                'workerName' => $assignment->worker?->user?->name,
                'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
                'workStartedAt' => $assignment->work_started_at?->toIso8601String(),
                'workFinishedAt' => $assignment->work_finished_at?->toIso8601String(),
                'serviceShareAmount' => $money($assignment->getRawOriginal('service_share_amount')),
                'travelAmount' => $money($assignment->getRawOriginal('travel_fee')),
                'adminMarginAmount' => $money($assignment->getRawOriginal('admin_margin_amount')),
                'workerAmount' => $money($assignment->getRawOriginal('worker_amount')),
                'currency' => $assignment->currency,
                'isFinal' => false,
            ])->values()->all(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function canonicalVisitInterval(
        CleaningBookingSession $session,
        Collection $schedules,
        Collection $roomPeriods,
    ): ?array {
        if ((string) $session->session_type === CleaningBookingSession::TYPE_OPEN_TIME) {
            if ($schedules->isNotEmpty() || $roomPeriods->isNotEmpty()) {
                return null;
            }

            $interval = $this->scheduled->openTimeInterval($session);
            if ($interval === null || $interval[1]->lessThanOrEqualTo($interval[0])) {
                return null;
            }

            return [CarbonImmutable::instance($interval[0]), CarbonImmutable::instance($interval[1])];
        }

        if ($schedules->isNotEmpty() && $roomPeriods->isNotEmpty()) {
            return null;
        }

        if ($schedules->isNotEmpty()) {
            return $this->roomScheduleInterval($schedules);
        }

        if ($roomPeriods->isNotEmpty()) {
            return $this->roomPeriodInterval($roomPeriods);
        }

        return null;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function roomScheduleInterval(Collection $schedules): ?array
    {
        $starts = [];
        $ends = [];

        foreach ($schedules as $schedule) {
            if (! $schedule instanceof CleaningRoomSchedule) {
                return null;
            }

            $start = $this->roomScheduleInstant($schedule->getRawOriginal('starts_at'));
            $end = $this->roomScheduleInstant($schedule->getRawOriginal('ends_at'));
            if ($start === null || $end === null || $end->lessThanOrEqualTo($start)) {
                return null;
            }

            $starts[] = $start;
            $ends[] = $end;
        }

        return $starts === [] ? null : [$this->earliest($starts), $this->latest($ends)];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function roomPeriodInterval(Collection $periods): ?array
    {
        $starts = [];
        $ends = [];

        foreach ($periods as $period) {
            if (! $period instanceof CleaningBookingRoomPeriod) {
                return null;
            }

            $interval = $this->roomPeriodWindow($period);
            if ($interval === null) {
                return null;
            }

            $starts[] = $interval[0];
            $ends[] = $interval[1];
        }

        return $starts === [] ? null : [$this->earliest($starts), $this->latest($ends)];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function roomPeriodWindow(CleaningBookingRoomPeriod $period): ?array
    {
        $date = $period->getRawOriginal('scheduled_date');
        $time = $period->getRawOriginal('scheduled_time');
        $duration = filter_var($period->getRawOriginal('estimated_duration_minutes'), FILTER_VALIDATE_INT);
        $snapshotDuration = filter_var(
            $period->pricing_snapshot['estimatedDurationMinutes'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if (! is_string($date) || ! preg_match('/^(\d{4}-\d{2}-\d{2})(?: 00:00:00)?$/D', $date, $dateParts)
            || ! is_string($time) || ! preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $time)
            || $duration === false || $duration <= 0 || $snapshotDuration !== $duration) {
            return null;
        }

        $civilTime = strlen($time) === 5 ? $time.':00' : $time;
        $start = $this->civilInstant($dateParts[1].' '.$civilTime);
        if ($start === null) {
            return null;
        }

        return [$start, $start->addMinutes($duration)];
    }

    private function roomScheduleInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        return $this->parseInstant($value, 'UTC');
    }

    private function civilInstant(string $value): ?CarbonImmutable
    {
        return $this->parseInstant($value, config('cleaning_booking_v2.timezone', 'Asia/Damascus'));
    }

    private function parseInstant(string $value, string $timezone): ?CarbonImmutable
    {
        foreach (['!Y-m-d H:i:s.u' => 'Y-m-d H:i:s.u', '!Y-m-d H:i:s' => 'Y-m-d H:i:s'] as $input => $output) {
            if (CarbonImmutable::hasFormat($value, $output)) {
                $instant = CarbonImmutable::createFromFormat($input, $value, $timezone);
                $errors = CarbonImmutable::getLastErrors();
                if ($instant instanceof CarbonImmutable
                    && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                    && $instant->format($output) === $value) {
                    return $instant;
                }
            }
        }

        return null;
    }

    /** @param list<CarbonImmutable> $instants */
    private function earliest(array $instants): CarbonImmutable
    {
        return array_reduce($instants, static fn (CarbonImmutable $earliest, CarbonImmutable $instant): CarbonImmutable =>
            $instant->lessThan($earliest) ? $instant : $earliest, $instants[0]);
    }

    /** @param list<CarbonImmutable> $instants */
    private function latest(array $instants): CarbonImmutable
    {
        return array_reduce($instants, static fn (CarbonImmutable $latest, CarbonImmutable $instant): CarbonImmutable =>
            $instant->greaterThan($latest) ? $instant : $latest, $instants[0]);
    }

    private function isoUtc(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->toIso8601String();
    }
}
