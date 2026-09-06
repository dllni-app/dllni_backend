from pathlib import Path


def write(path: str, content: str) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content, encoding='utf-8')


def replace(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    if new in text:
        return
    if old not in text:
        raise SystemExit(f'pattern not found in {path}: {old[:120]!r}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


write('config/cleaning_attendance.php', '''<?php

declare(strict_types=1);

return [
    'late_grace_minutes' => (int) env('CLEANING_RECURRING_LATE_GRACE_MINUTES', 15),
    'no_travel_grace_minutes' => (int) env('CLEANING_RECURRING_NO_TRAVEL_GRACE_MINUTES', 30),
];
''')

write('Modules/Cleaning/database/migrations/2026_09_06_230530_add_attendance_tracking_to_cleaning_booking_session_worker_assignments_table.php', '''<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
            $table->timestamp('late_reported_at')->nullable()->after('released_reason');
            $table->timestamp('no_travel_reported_at')->nullable()->after('late_reported_at');
            $table->string('attendance_action')->nullable()->after('no_travel_reported_at');
            $table->timestamp('attendance_resolved_at')->nullable()->after('attendance_action');
            $table->text('attendance_note')->nullable()->after('attendance_resolved_at');

            $table->index('late_reported_at', 'cleaning_session_assignment_late_idx');
            $table->index('no_travel_reported_at', 'cleaning_session_assignment_no_travel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
            $table->dropIndex('cleaning_session_assignment_late_idx');
            $table->dropIndex('cleaning_session_assignment_no_travel_idx');
            $table->dropColumn([
                'late_reported_at',
                'no_travel_reported_at',
                'attendance_action',
                'attendance_resolved_at',
                'attendance_note',
            ]);
        });
    }
};
''')

write('Modules/Cleaning/app/Services/CleaningBookingSessionAttendanceService.php', '''<?php

declare(strict_types=1);

namespace Modules\\Cleaning\\Services;

use Carbon\\CarbonImmutable;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;
use Modules\\Cleaning\\Enums\\CleaningBookingSessionCoverageStatus;
use Modules\\Cleaning\\Enums\\CleaningBookingSessionStatus;
use Modules\\Cleaning\\Enums\\CleaningBookingWorkerAssignmentStatus;
use Modules\\Cleaning\\Models\\CleaningBooking;
use Modules\\Cleaning\\Models\\CleaningBookingSession;
use Modules\\Cleaning\\Models\\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionAttendanceService
{
    public const ACTION_WAIT = 'wait';

    public const ACTION_REPLACE = 'replace';

    public const ACTION_CANCEL = 'cancel';

    public function __construct(
        private readonly CleaningBookingSessionParentStateService $parentState,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    /** @param array<int, int> $workerIds */
    public function handle(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        array $workerIds,
        string $action,
        ?string $note = null,
    ): CleaningBookingSession {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        $action = mb_strtolower(mb_trim($action));
        if (! in_array($action, [self::ACTION_WAIT, self::ACTION_REPLACE, self::ACTION_CANCEL], true)) {
            throw new InvalidArgumentException('Unsupported attendance action.');
        }

        $workerIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $workerId): int => (int) $workerId, $workerIds),
            static fn (int $workerId): bool => $workerId > 0,
        )));
        if ($workerIds === []) {
            throw new InvalidArgumentException('Select at least one worker for the attendance action.');
        }

        $note = $this->nullableTrimmed($note);
        $affectedWorkerIds = [];

        $updated = DB::transaction(function () use (
            $booking,
            $session,
            $workerIds,
            $action,
            $note,
            &$affectedWorkerIds,
        ): CleaningBookingSession {
            $locked = CleaningBookingSession::query()
                ->whereKey($session->id)
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof CleaningBookingSession) {
                throw new InvalidArgumentException('Session does not belong to this booking.');
            }
            if ((string) $locked->session_type !== CleaningBookingSession::TYPE_RECURRING_CLEANING) {
                throw new InvalidArgumentException('Attendance escalation is available only for recurring cleaning visits.');
            }
            if ($locked->isTerminal() || ! in_array($locked->status, [
                CleaningBookingSessionStatus::Scheduled,
                CleaningBookingSessionStatus::WorkerAssigned,
            ], true)) {
                throw new InvalidArgumentException('This visit can no longer use late or no-travel options.');
            }
            if ($locked->work_started_at !== null) {
                throw new InvalidArgumentException('Attendance escalation is unavailable after work starts.');
            }

            $startsAt = $locked->startsAt();
            if (! $startsAt instanceof CarbonImmutable) {
                throw new InvalidArgumentException('Visit start time is unavailable.');
            }

            $now = CarbonImmutable::now(config('app.timezone'));
            $lateGrace = max(0, (int) config('cleaning_attendance.late_grace_minutes', 15));
            $noTravelGrace = max($lateGrace, (int) config('cleaning_attendance.no_travel_grace_minutes', 30));
            $requiredGrace = $action === self::ACTION_WAIT ? $lateGrace : $noTravelGrace;
            if ($now->lt($startsAt->addMinutes($requiredGrace))) {
                throw new InvalidArgumentException(
                    $action === self::ACTION_WAIT
                        ? 'The late grace period has not elapsed yet.'
                        : 'The no-travel grace period has not elapsed yet.'
                );
            }

            $selected = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('worker_id', $workerIds)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($selected->count() !== count($workerIds)) {
                throw new InvalidArgumentException('One or more selected workers are no longer active on this visit.');
            }
            foreach ($selected as $assignment) {
                if ($assignment->started_travel_at !== null || $assignment->arrived_at !== null || $assignment->work_started_at !== null) {
                    throw new InvalidArgumentException('A selected worker already started travel or execution.');
                }
            }

            $reportedAt = now();
            $affectedWorkerIds = $workerIds;

            if ($action === self::ACTION_WAIT) {
                foreach ($selected as $assignment) {
                    $assignment->forceFill([
                        'late_reported_at' => $assignment->late_reported_at ?? $reportedAt,
                        'attendance_action' => self::ACTION_WAIT,
                        'attendance_note' => $note,
                    ])->save();
                }

                return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
            }

            if ($action === self::ACTION_REPLACE) {
                foreach ($selected as $assignment) {
                    $assignment->forceFill([
                        'late_reported_at' => $assignment->late_reported_at ?? $reportedAt,
                        'no_travel_reported_at' => $assignment->no_travel_reported_at ?? $reportedAt,
                        'attendance_action' => self::ACTION_REPLACE,
                        'attendance_resolved_at' => $reportedAt,
                        'attendance_note' => $note,
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                        'released_at' => $reportedAt,
                        'released_reason' => 'Customer reported no travel and requested replacement.',
                    ])->save();
                }

                $this->syncCoverageAfterRelease($locked);

                return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
            }

            $allActive = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $affectedWorkerIds = $allActive->pluck('worker_id')
                ->map(static fn (mixed $workerId): int => (int) $workerId)
                ->filter(static fn (int $workerId): bool => $workerId > 0)
                ->unique()
                ->values()
                ->all();
            $selectedWorkerLookup = array_fill_keys($workerIds, true);

            foreach ($allActive as $assignment) {
                $isReportedWorker = isset($selectedWorkerLookup[(int) $assignment->worker_id]);
                $assignment->forceFill([
                    'late_reported_at' => $isReportedWorker ? ($assignment->late_reported_at ?? $reportedAt) : $assignment->late_reported_at,
                    'no_travel_reported_at' => $isReportedWorker ? ($assignment->no_travel_reported_at ?? $reportedAt) : $assignment->no_travel_reported_at,
                    'attendance_action' => $isReportedWorker ? self::ACTION_CANCEL : $assignment->attendance_action,
                    'attendance_resolved_at' => $isReportedWorker ? $reportedAt : $assignment->attendance_resolved_at,
                    'attendance_note' => $isReportedWorker ? $note : $assignment->attendance_note,
                    'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                    'released_at' => $reportedAt,
                    'released_reason' => $isReportedWorker
                        ? 'Customer cancelled visit after reported worker no-travel.'
                        : 'Visit cancelled because required worker did not start travel.',
                ])->save();
            }

            $locked->forceFill([
                'status' => CleaningBookingSessionStatus::Cancelled,
                'cancellation_fee' => 0,
                'cancelled_at' => $reportedAt,
                'cancellation_reason' => 'Worker no-travel'.($note !== null ? ': '.$note : ''),
                'cancelled_by_role' => 'customer',
                'version' => max(1, (int) $locked->version) + 1,
            ])->save();
            $this->financialAggregation->sync($booking);

            return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
        }, 3);

        $this->parentState->refresh($booking);
        $freshBooking = $booking->fresh(['customer']) ?? $booking;
        foreach ($affectedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $freshBooking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: match ($action) {
                    self::ACTION_WAIT => 'customer_reported_worker_late',
                    self::ACTION_REPLACE => 'customer_reported_no_travel_replacement',
                    default => 'customer_cancelled_session_after_no_travel',
                },
                actorRole: 'customer',
                occurredAt: now()->toIso8601String(),
                extraData: [
                    'sessionId' => (int) $updated->id,
                    'attendanceAction' => $action,
                    'attendanceNote' => $note,
                ],
            );
        }

        return $updated->fresh(['workerAssignments.worker.user']) ?? $updated;
    }

    private function syncCoverageAfterRelease(CleaningBookingSession $session): void
    {
        $acceptedCount = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $session->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->count();
        $requiredCount = $session->requiredWorkerCount();
        $coverage = match (true) {
            $acceptedCount <= 0 => CleaningBookingSessionCoverageStatus::Searching,
            $acceptedCount < $requiredCount => CleaningBookingSessionCoverageStatus::PartiallyCovered,
            default => CleaningBookingSessionCoverageStatus::FullyCovered,
        };

        $session->forceFill([
            'coverage_status' => $coverage,
            'status' => $acceptedCount > 0
                ? CleaningBookingSessionStatus::WorkerAssigned
                : CleaningBookingSessionStatus::Scheduled,
            'version' => max(1, (int) $session->version) + 1,
        ])->save();
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $value = $value !== null ? mb_trim($value) : null;

        return $value === '' ? null : $value;
    }
}
''')

replace(
    'Modules/Cleaning/app/Models/CleaningBookingSessionWorkerAssignment.php',
    "        'released_reason',\n        'started_travel_at',",
    "        'released_reason',\n        'late_reported_at',\n        'no_travel_reported_at',\n        'attendance_action',\n        'attendance_resolved_at',\n        'attendance_note',\n        'started_travel_at',",
)
replace(
    'Modules/Cleaning/app/Models/CleaningBookingSessionWorkerAssignment.php',
    "            'released_at' => 'datetime',\n            'started_travel_at' => 'datetime',",
    "            'released_at' => 'datetime',\n            'late_reported_at' => 'datetime',\n            'no_travel_reported_at' => 'datetime',\n            'attendance_resolved_at' => 'datetime',\n            'started_travel_at' => 'datetime',",
)

replace(
    'Modules/Cleaning/app/Services/CleaningBookingSessionLifecycleService.php',
    "                $assignment->forceFill(['started_travel_at' => $startedAt])->save();",
    "                $attendanceResolvedAt = ($assignment->late_reported_at !== null || $assignment->no_travel_reported_at !== null)\n                    ? ($assignment->attendance_resolved_at ?? $startedAt)\n                    : $assignment->attendance_resolved_at;\n                $assignment->forceFill([\n                    'started_travel_at' => $startedAt,\n                    'attendance_resolved_at' => $attendanceResolvedAt,\n                ])->save();",
)

replace(
    'Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php',
    "        $paymentStatus = in_array($status, [\n            CleaningBookingSessionStatus::Cancelled->value,\n            CleaningBookingSessionStatus::Skipped->value,\n            CleaningBookingSessionStatus::Superseded->value,\n        ], true)\n            ? 'not_required'\n            : ((string) ($session->payment_status ?: 'pending'));\n\n        return [",
    "        $paymentStatus = in_array($status, [\n            CleaningBookingSessionStatus::Cancelled->value,\n            CleaningBookingSessionStatus::Skipped->value,\n            CleaningBookingSessionStatus::Superseded->value,\n        ], true)\n            ? 'not_required'\n            : ((string) ($session->payment_status ?: 'pending'));\n        $lateGraceMinutes = max(0, (int) config('cleaning_attendance.late_grace_minutes', 15));\n        $noTravelGraceMinutes = max($lateGraceMinutes, (int) config('cleaning_attendance.no_travel_grace_minutes', 30));\n        $minutesPastStart = $startsAt !== null && $startsAt->lte($now)\n            ? (int) floor($startsAt->diffInMinutes($now))\n            : 0;\n        $attendanceEligibleAssignments = $session->workerAssignments\n            ->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()\n                && $assignment->started_travel_at === null\n                && $assignment->arrived_at === null\n                && $assignment->work_started_at === null)\n            ->values();\n        $lateWorkerIds = $minutesPastStart >= $lateGraceMinutes\n            ? $attendanceEligibleAssignments->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()\n            : collect();\n        $noTravelWorkerIds = $minutesPastStart >= $noTravelGraceMinutes\n            ? $attendanceEligibleAssignments->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()\n            : collect();\n        $reportableLateWorkerIds = $minutesPastStart >= $lateGraceMinutes\n            ? $attendanceEligibleAssignments->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->late_reported_at === null)\n                ->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()\n            : collect();\n        $reportableNoTravelWorkerIds = $minutesPastStart >= $noTravelGraceMinutes\n            ? $attendanceEligibleAssignments->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->no_travel_reported_at === null)\n                ->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()\n            : collect();\n        $attendanceIncidents = $session->workerAssignments\n            ->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->late_reported_at !== null\n                || $assignment->no_travel_reported_at !== null)\n            ->map(function (CleaningBookingSessionWorkerAssignment $assignment): array {\n                $worker = $assignment->worker;\n                $user = $worker?->user;\n\n                return [\n                    'workerId' => (int) $assignment->worker_id,\n                    'workerName' => $worker?->first_name ?: $user?->name,\n                    'lateReportedAt' => $assignment->late_reported_at?->toIso8601String(),\n                    'noTravelReportedAt' => $assignment->no_travel_reported_at?->toIso8601String(),\n                    'action' => $assignment->attendance_action,\n                    'resolvedAt' => $assignment->attendance_resolved_at?->toIso8601String(),\n                    'note' => $assignment->attendance_note,\n                ];\n            })->values();\n        $canUseAttendanceActions = $isCustomerView\n            && (string) $session->session_type === CleaningBookingSession::TYPE_RECURRING_CLEANING\n            && in_array($status, [\n                CleaningBookingSessionStatus::Scheduled->value,\n                CleaningBookingSessionStatus::WorkerAssigned->value,\n            ], true)\n            && ! $session->isTerminal();\n\n        return [",
)
replace(
    'Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php',
    "            'isFullyCovered' => $session->isFullyCovered(),\n            'paymentStatus' => $paymentStatus,",
    "            'isFullyCovered' => $session->isFullyCovered(),\n            'canReportLate' => $canUseAttendanceActions && $reportableLateWorkerIds->isNotEmpty(),\n            'canReportNoTravel' => $canUseAttendanceActions && $reportableNoTravelWorkerIds->isNotEmpty(),\n            'lateWorkerIds' => $lateWorkerIds->all(),\n            'noTravelWorkerIds' => $noTravelWorkerIds->all(),\n            'reportableLateWorkerIds' => $reportableLateWorkerIds->all(),\n            'reportableNoTravelWorkerIds' => $reportableNoTravelWorkerIds->all(),\n            'attendance' => [\n                'lateGraceMinutes' => $lateGraceMinutes,\n                'noTravelGraceMinutes' => $noTravelGraceMinutes,\n                'minutesPastStart' => $minutesPastStart,\n                'incidents' => $attendanceIncidents->all(),\n            ],\n            'paymentStatus' => $paymentStatus,",
)
replace(
    'Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php',
    "            'acceptedAt' => $assignment->accepted_at?->toIso8601String(),\n            'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),",
    "            'acceptedAt' => $assignment->accepted_at?->toIso8601String(),\n            'lateReportedAt' => $assignment->late_reported_at?->toIso8601String(),\n            'noTravelReportedAt' => $assignment->no_travel_reported_at?->toIso8601String(),\n            'attendanceAction' => $assignment->attendance_action,\n            'attendanceResolvedAt' => $assignment->attendance_resolved_at?->toIso8601String(),\n            'attendanceNote' => $assignment->attendance_note,\n            'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),",
)

replace(
    'Modules/Cleaning/app/Http/Controllers/API/CleaningBookingSessionLifecycleController.php',
    "use Modules\\Cleaning\\Services\\CleaningBookingSessionCancellationService;\nuse Modules\\Cleaning\\Services\\CleaningBookingSessionLifecycleService;",
    "use Modules\\Cleaning\\Services\\CleaningBookingSessionAttendanceService;\nuse Modules\\Cleaning\\Services\\CleaningBookingSessionCancellationService;\nuse Modules\\Cleaning\\Services\\CleaningBookingSessionLifecycleService;",
)
replace(
    'Modules/Cleaning/app/Http/Controllers/API/CleaningBookingSessionLifecycleController.php',
    "        private readonly CleaningBookingSessionLifecycleService $lifecycle,\n        private readonly CleaningBookingSessionCancellationService $cancellation,",
    "        private readonly CleaningBookingSessionLifecycleService $lifecycle,\n        private readonly CleaningBookingSessionAttendanceService $attendance,\n        private readonly CleaningBookingSessionCancellationService $cancellation,",
)
replace(
    'Modules/Cleaning/app/Http/Controllers/API/CleaningBookingSessionLifecycleController.php',
    "    public function sos(\n        CleaningBookingSosRequest $request,",
    "    public function attendance(\n        Request $request,\n        CleaningBooking $cleaning_booking,\n        CleaningBookingSession $cleaning_booking_session,\n    ): JsonResponse {\n        $validated = $request->validate([\n            'workerIds' => ['required', 'array', 'min:1'],\n            'workerIds.*' => ['required', 'integer', 'distinct', 'exists:workers,id'],\n            'action' => ['required', 'string', 'in:wait,replace,cancel'],\n            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],\n        ]);\n        $user = $request->user();\n        if ($user === null || (int) $cleaning_booking->customer_id !== (int) $user->id) {\n            abort(403, 'Only the booking customer can use attendance options.');\n        }\n\n        try {\n            $session = $this->attendance->handle(\n                booking: $cleaning_booking,\n                session: $cleaning_booking_session,\n                customerId: (int) $user->id,\n                workerIds: array_map('intval', $validated['workerIds']),\n                action: (string) $validated['action'],\n                note: isset($validated['note']) ? (string) $validated['note'] : null,\n            );\n        } catch (InvalidArgumentException $e) {\n            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);\n        }\n\n        return $this->payload($cleaning_booking, $session);\n    }\n\n    public function sos(\n        CleaningBookingSosRequest $request,",
)

replace(
    'Modules/Cleaning/routes/sessions.php',
    "        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/sos',",
    "        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/attendance',\n            [CleaningBookingSessionLifecycleController::class, 'attendance'],\n        )->name('cleaning-bookings.sessions.attendance');\n\n        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/sos',",
)

replace(
    'app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php',
    "                TextColumn::make('disputes_count')",
    "                TextColumn::make('late_sessions_count')\n                    ->label(self::headerLabel('زيارات أُبلغ عن تأخرها', 'عدد الزيارات التي سجل العميل فيها تأخر عامل قبل بدء التنقل.'))\n                    ->getStateUsing(fn (CleaningBooking $record): int => $record->sessions\n                        ->filter(fn ($session): bool => $session->workerAssignments->contains(\n                            fn ($assignment): bool => $assignment->late_reported_at !== null,\n                        ))->count())\n                    ->badge()\n                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'gray')\n                    ->toggleable(),\n                TextColumn::make('no_travel_sessions_count')\n                    ->label(self::headerLabel('زيارات عدم التنقل', 'عدد الزيارات التي بلغ فيها العميل أن العامل لم يبدأ التنقل بعد مهلة عدم التنقل.'))\n                    ->getStateUsing(fn (CleaningBooking $record): int => $record->sessions\n                        ->filter(fn ($session): bool => $session->workerAssignments->contains(\n                            fn ($assignment): bool => $assignment->no_travel_reported_at !== null,\n                        ))->count())\n                    ->badge()\n                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')\n                    ->toggleable(),\n                TextColumn::make('disputes_count')",
)
replace(
    'app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php',
    "                    'sessions',\n                ])",
    "                    'sessions.workerAssignments.worker.user',\n                ])",
)
replace(
    'app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php',
    "                Filter::make('scheduled_today')",
    "                Filter::make('has_late_session')\n                    ->label('يوجد بلاغ تأخر')\n                    ->query(fn (Builder $query): Builder => $query->whereHas(\n                        'sessions.workerAssignments',\n                        fn (Builder $assignmentQuery): Builder => $assignmentQuery->whereNotNull('late_reported_at'),\n                    )),\n                Filter::make('has_no_travel_session')\n                    ->label('يوجد بلاغ عدم تنقل')\n                    ->query(fn (Builder $query): Builder => $query->whereHas(\n                        'sessions.workerAssignments',\n                        fn (Builder $assignmentQuery): Builder => $assignmentQuery->whereNotNull('no_travel_reported_at'),\n                    )),\n                Filter::make('scheduled_today')",
)

replace(
    'app/Filament/Resources/CleaningBookings/Pages/ViewCleaningBooking.php',
    "            'timeWarnings.worker.user',\n        ]);",
    "            'timeWarnings.worker.user',\n            'sessions.workerAssignments.worker.user',\n        ]);",
)
replace(
    'app/Filament/Resources/CleaningBookings/Pages/ViewCleaningBooking.php',
    "            ...$existingComponents,\n            Section::make('تقييم العميل ومراجعته')",
    "            ...$existingComponents,\n            Section::make('تقارير التأخر وعدم التنقل')\n                ->description('سجل بلاغات العميل لكل زيارة دورية وعامل، مع الإجراء الذي اختاره ووقت إغلاق الحالة.')\n                ->schema([\n                    RepeatableEntry::make('attendance_incidents')\n                        ->hiddenLabel()\n                        ->getStateUsing(fn (CleaningBooking $record): array => self::attendanceIncidents($record))\n                        ->schema([\n                            TextEntry::make('session')\n                                ->label('الزيارة'),\n                            TextEntry::make('worker')\n                                ->label('العامل')\n                                ->placeholder('-'),\n                            TextEntry::make('issue')\n                                ->label('البلاغ')\n                                ->badge()\n                                ->color(fn (string $state): string => $state === 'عدم بدء التنقل' ? 'danger' : 'warning'),\n                            TextEntry::make('action')\n                                ->label('قرار العميل')\n                                ->badge(),\n                            TextEntry::make('reported_at')\n                                ->label('وقت البلاغ')\n                                ->placeholder('-'),\n                            TextEntry::make('resolved_at')\n                                ->label('وقت الإغلاق')\n                                ->placeholder('مفتوح'),\n                            TextEntry::make('note')\n                                ->label('ملاحظة العميل')\n                                ->placeholder('لا توجد ملاحظة')\n                                ->columnSpanFull(),\n                        ])\n                        ->columns([\n                            'default' => 1,\n                            'md' => 2,\n                            'xl' => 3,\n                        ]),\n                ])\n                ->visible(fn (CleaningBooking $record): bool => self::attendanceIncidents($record) !== [])\n                ->columnSpanFull(),\n            Section::make('تقييم العميل ومراجعته')",
)
replace(
    'app/Filament/Resources/CleaningBookings/Pages/ViewCleaningBooking.php',
    "    private static function customerWorkerRatings(CleaningBooking $record): array\n    {",
    "    /** @return array<int, array<string, string|null>> */\n    private static function attendanceIncidents(CleaningBooking $record): array\n    {\n        return $record->sessions\n            ->flatMap(function ($session): array {\n                return $session->workerAssignments\n                    ->filter(fn ($assignment): bool => $assignment->late_reported_at !== null || $assignment->no_travel_reported_at !== null)\n                    ->map(function ($assignment) use ($session): array {\n                        $isNoTravel = $assignment->no_travel_reported_at !== null;\n                        $reportedAt = $isNoTravel ? $assignment->no_travel_reported_at : $assignment->late_reported_at;\n\n                        return [\n                            'session' => sprintf(\n                                'زيارة %d — %s %s',\n                                (int) $session->sequence,\n                                $session->scheduled_date?->format('Y-m-d') ?? '-',\n                                (string) $session->scheduled_time,\n                            ),\n                            'worker' => $assignment->worker?->user?->name ?? $assignment->worker?->first_name ?? '-',\n                            'issue' => $isNoTravel ? 'عدم بدء التنقل' : 'تأخر',\n                            'action' => self::attendanceActionLabel($assignment->attendance_action),\n                            'reported_at' => $reportedAt?->format('Y-m-d h:i A'),\n                            'resolved_at' => $assignment->attendance_resolved_at?->format('Y-m-d h:i A'),\n                            'note' => filled($assignment->attendance_note) ? (string) $assignment->attendance_note : null,\n                        ];\n                    })->values()->all();\n            })\n            ->values()\n            ->all();\n    }\n\n    private static function attendanceActionLabel(?string $action): string\n    {\n        return match ($action) {\n            'wait' => 'انتظار العامل',\n            'replace' => 'طلب استبدال العامل',\n            'cancel' => 'إلغاء الزيارة دون غرامة',\n            default => 'لم يحدد إجراء',\n        };\n    }\n\n    private static function customerWorkerRatings(CleaningBooking $record): array\n    {",
)

write('tests/Feature/Cleaning/RecurringCleaningAttendanceTest.php', '''<?php

declare(strict_types=1);

use App\\Models\\User;
use App\\Models\\Worker;
use Laravel\\Sanctum\\Sanctum;
use Modules\\Cleaning\\Enums\\CleaningBookingSessionCoverageStatus;
use Modules\\Cleaning\\Enums\\CleaningBookingSessionStatus;
use Modules\\Cleaning\\Enums\\CleaningBookingStatus;
use Modules\\Cleaning\\Enums\\CleaningBookingWorkerAssignmentStatus;
use Modules\\Cleaning\\Models\\CleaningBooking;
use Modules\\Cleaning\\Models\\CleaningBookingSession;
use Modules\\Cleaning\\Models\\CleaningBookingSessionWorkerAssignment;

use function Pest\\Laravel\\postJson;

/** @return array{0:User,1:Worker,2:CleaningBooking,3:CleaningBookingSession,4:CleaningBookingSessionWorkerAssignment} */
function makeRecurringAttendanceVisit(int $minutesPastStart): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $scheduledAt = now(config('app.timezone'))->subMinutes($minutesPastStart);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'number_of_workers' => 1,
        'base_price' => 1000,
        'admin_margin_amount' => 100,
        'total_price' => 1100,
    ]);
    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => CleaningBookingSessionCoverageStatus::FullyCovered,
        'status' => CleaningBookingSessionStatus::WorkerAssigned,
        'base_price' => 1000,
        'admin_margin_amount' => 100,
        'total_price' => 1100,
    ]);
    $assignment = CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted,
        'accepted_at' => now()->subHour(),
        'service_share_amount' => 1000,
        'admin_margin_amount' => 100,
        'worker_amount' => 900,
        'currency' => 'SYP',
    ]);

    return [$customer, $worker, $booking, $session, $assignment];
}

it('allows a customer to report lateness after the late grace period and resolves it when travel starts', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session, $assignment] = makeRecurringAttendanceVisit(20);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'wait',
        'note' => 'سأنتظر قليلاً',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canReportLate', false)
        ->assertJsonPath('data.schedule.sessions.0.canReportNoTravel', false)
        ->assertJsonPath('data.schedule.sessions.0.attendance.incidents.0.action', 'wait');

    $assignment->refresh();
    expect($assignment->late_reported_at)->not->toBeNull()
        ->and($assignment->attendance_action)->toBe('wait')
        ->and($assignment->attendance_resolved_at)->toBeNull();

    Sanctum::actingAs($worker->user);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/start-travel")
        ->assertOk();

    expect($assignment->fresh()->attendance_resolved_at)->not->toBeNull();
});

it('reopens recurring coverage when the customer requests replacement after no travel', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session, $assignment] = makeRecurringAttendanceVisit(40);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'replace',
        'note' => 'العامل لم يبدأ التنقل',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.0.coverageStatus', CleaningBookingSessionCoverageStatus::Searching->value)
        ->assertJsonPath('data.schedule.sessions.0.attendance.incidents.0.action', 'replace');

    $assignment->refresh();
    expect($assignment->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($assignment->no_travel_reported_at)->not->toBeNull()
        ->and($assignment->attendance_resolved_at)->not->toBeNull();
});

it('lets the customer cancel a recurring visit without a customer fee after confirmed no travel', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session, $assignment] = makeRecurringAttendanceVisit(40);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'cancel',
        'note' => 'لا أريد انتظار بديل',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Cancelled->value)
        ->assertJsonPath('data.schedule.sessions.0.pricing.cancellationFee', 0);

    $session->refresh();
    expect($session->status)->toBe(CleaningBookingSessionStatus::Cancelled)
        ->and((float) $session->cancellation_fee)->toBe(0.0)
        ->and($assignment->fresh()->no_travel_reported_at)->not->toBeNull();
});

it('rejects attendance escalation before its configured grace period', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session] = makeRecurringAttendanceVisit(5);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'wait',
    ])->assertUnprocessable();
});
''')
