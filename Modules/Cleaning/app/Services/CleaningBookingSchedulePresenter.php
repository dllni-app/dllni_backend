<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\DisputeStatus;
use App\Enums\WorkerCustomerRatingType;
use App\Models\Dispute;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Throwable;

final class CleaningBookingSchedulePresenter
{
    public function __construct(
        private readonly CleaningOpenTimeBillingService $openTimeBilling,
    ) {}

    /** @return array<string, mixed> */
    public function present(CleaningBooking $booking, ?Worker $viewerWorker = null): array
    {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->with(['workerAssignments.worker.user', 'ratings', 'disputes'])
            ->orderBy('sequence')
            ->get();

        if ($sessions->isEmpty()) {
            return $this->singleDayFallback($booking);
        }

        $completed = $sessions->filter(
            fn (CleaningBookingSession $session): bool => $this->status($session) === CleaningBookingSessionStatus::Completed->value,
        )->count();
        $cancelled = $sessions->filter(
            fn (CleaningBookingSession $session): bool => $this->status($session) === CleaningBookingSessionStatus::Cancelled->value,
        )->count();
        $skipped = $sessions->filter(
            fn (CleaningBookingSession $session): bool => $this->status($session) === CleaningBookingSessionStatus::Skipped->value,
        )->count();

        $next = $sessions
            ->filter(fn (CleaningBookingSession $session): bool => ! $session->isTerminal()
                && $this->status($session) !== CleaningBookingSessionStatus::Paused->value)
            ->sortBy(fn (CleaningBookingSession $session): string => ($session->startsAt()?->toIso8601String() ?? '9999'))
            ->first();
        $firstDate = $sessions->min('scheduled_date');
        $lastDate = $sessions->max('scheduled_date');
        $canReschedule = $this->eventScheduleCanReschedule($booking, $sessions, $viewerWorker);
        $isRecurring = $sessions->contains(
            fn (CleaningBookingSession $session): bool => (string) $session->session_type === CleaningBookingSession::TYPE_RECURRING_CLEANING,
        );
        $isOpenTime = $sessions->contains(
            fn (CleaningBookingSession $session): bool => (string) $session->session_type === CleaningBookingSession::TYPE_OPEN_TIME,
        );
        $isRecurringPaused = $isRecurring && $booking->recurring_paused_at !== null;
        $isCustomerView = ! $viewerWorker instanceof Worker;
        $canPauseRecurring = $isCustomerView
            && $isRecurring
            && ! $isRecurringPaused
            && $sessions->contains(fn (CleaningBookingSession $session): bool => $this->canPauseRecurringSession($session));
        $canResumeRecurring = $isCustomerView
            && $isRecurringPaused
            && $sessions->contains(
                fn (CleaningBookingSession $session): bool => $this->status($session) === CleaningBookingSessionStatus::Paused->value,
            );

        return [
            // `multi_day` is deliberately preserved because both legacy Flutter
            // clients already understand it. `isMultiSession` is the canonical
            // new meaning and permits the same contract to serve recurring work.
            'mode' => $sessions->count() > 1 ? 'multi_day' : 'single_day',
            'isRecurring' => $isRecurring,
            'isOpenTime' => $isOpenTime,
            'isPaused' => $isRecurringPaused,
            'workerScope' => $booking->resolvedWorkerScope(),
            'specificWorkerIds' => $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC
                ? $booking->specificWorkerIds()
                : [],
            'canPause' => $canPauseRecurring,
            'canResume' => $canResumeRecurring,
            'pausedAt' => $booking->recurring_paused_at?->toIso8601String(),
            'pauseReason' => $booking->recurring_pause_reason,
            'isMultiSession' => $sessions->count() > 1,
            'isMultiDay' => $sessions->count() > 1,
            'sessionsCount' => $sessions->count(),
            'daysCount' => $sessions->count(),
            'completedSessionsCount' => $completed,
            'completedDaysCount' => $completed,
            'cancelledSessionsCount' => $cancelled,
            'cancelledDaysCount' => $cancelled,
            'skippedSessionsCount' => $skipped,
            'remainingSessionsCount' => max(0, $sessions->count() - $completed - $cancelled - $skipped),
            'remainingDaysCount' => max(0, $sessions->count() - $completed - $cancelled - $skipped),
            'totalHours' => round((float) $sessions
                ->reject(fn (CleaningBookingSession $session): bool => in_array($this->status($session), [
                    CleaningBookingSessionStatus::Cancelled->value,
                    CleaningBookingSessionStatus::Skipped->value,
                ], true))
                ->sum('duration_hours'), 2),
            'firstDate' => $firstDate?->format('Y-m-d'),
            'lastDate' => $lastDate?->format('Y-m-d'),
            'nextSession' => $next instanceof CleaningBookingSession
                ? $this->sessionPayload($next, $viewerWorker, $canReschedule)
                : null,
            'sessions' => $sessions
                ->map(fn (CleaningBookingSession $session): array => $this->sessionPayload($session, $viewerWorker, $canReschedule))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionPayload(CleaningBookingSession $session, ?Worker $viewerWorker, bool $canReschedule): array
    {
        $acceptedAssignments = $session->workerAssignments
            ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isAccepted())
            ->values();
        $myAssignment = $viewerWorker instanceof Worker
            ? $acceptedAssignments->firstWhere('worker_id', (int) $viewerWorker->id)
            : null;
        $myAssignmentPayload = $myAssignment instanceof CleaningBookingSessionWorkerAssignment
            ? $this->assignmentPayload($myAssignment)
            : null;
        $startsAt = $session->startsAt();
        $endsAt = $session->endsAt();
        $now = CarbonImmutable::now(config('app.timezone'));
        $status = $this->status($session);
        $isCustomerView = ! $viewerWorker instanceof Worker;
        $hasMyActiveAssignment = $myAssignment instanceof CleaningBookingSessionWorkerAssignment
            && $myAssignment->isActive();
        $canStartTravel = $hasMyActiveAssignment
            && in_array($status, [
                CleaningBookingSessionStatus::Scheduled->value,
                CleaningBookingSessionStatus::WorkerAssigned->value,
            ], true)
            && $myAssignment->started_travel_at === null;
        $canArrive = $hasMyActiveAssignment
            && $myAssignment->started_travel_at !== null
            && $myAssignment->arrived_at === null
            && in_array($status, [
                CleaningBookingSessionStatus::Scheduled->value,
                CleaningBookingSessionStatus::WorkerAssigned->value,
                CleaningBookingSessionStatus::AwaitingStartVerification->value,
            ], true);
        $canStartWork = $hasMyActiveAssignment
            && $status === CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation->value;
        $canComplete = $hasMyActiveAssignment
            && (string) $session->session_type !== CleaningBookingSession::TYPE_OPEN_TIME
            && $status === CleaningBookingSessionStatus::InProgress->value;
        $openTime = $this->openTimePayload($session);
        $openTimeIsRunning = $openTime !== null
            && ($openTime['workStartedAt'] ?? null) !== null
            && ($openTime['workFinishedAt'] ?? null) === null
            && ! (bool) ($openTime['isFinalized'] ?? false);
        $canRequestOpenTimeExtension = $isCustomerView
            && $openTimeIsRunning
            && ($openTime['pendingExtension'] ?? null) === null
            && (int) ($openTime['expectedMaxMinutes'] ?? 0) < (int) ($openTime['hardMaxMinutes'] ?? 0);
        $canRequestOpenTimeEnd = $isCustomerView
            && $openTimeIsRunning
            && (string) ($openTime['endStatus'] ?? '') !== 'pending';
        $canDecideOpenTimeEnd = $hasMyActiveAssignment
            && $openTimeIsRunning
            && (string) ($openTime['endStatus'] ?? '') === 'pending';
        $canCancel = $isCustomerView
            ? ! $session->isTerminal()
                && $session->work_started_at === null
                && in_array($status, [
                    CleaningBookingSessionStatus::Scheduled->value,
                    CleaningBookingSessionStatus::WorkerAssigned->value,
                    CleaningBookingSessionStatus::AwaitingStartVerification->value,
                    CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation->value,
                ], true)
            : $hasMyActiveAssignment
                && $myAssignment->started_travel_at === null
                && $session->work_started_at === null
                && in_array($status, [
                    CleaningBookingSessionStatus::Scheduled->value,
                    CleaningBookingSessionStatus::WorkerAssigned->value,
                ], true);
        $canConfirmStartVerification = $isCustomerView
            && $status === CleaningBookingSessionStatus::AwaitingStartVerification->value;
        $canConfirmCompletion = $isCustomerView
            && $status === CleaningBookingSessionStatus::AwaitingCustomerCompletion->value;
        $hasStartedTravelAssignment = $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && $assignment->started_travel_at !== null,
        );
        $canSkip = $isCustomerView
            && (string) $session->session_type === 'recurring_cleaning'
            && in_array($status, [
                CleaningBookingSessionStatus::Scheduled->value,
                CleaningBookingSessionStatus::WorkerAssigned->value,
            ], true)
            && ! $session->isTerminal()
            && $startsAt !== null
            && $startsAt->gt($now)
            && $session->started_travel_at === null
            && $session->work_started_at === null
            && ! $hasStartedTravelAssignment;
        $canSendSos = ! $session->isTerminal()
            && $status !== CleaningBookingSessionStatus::Paused->value
            && ($isCustomerView || $hasMyActiveAssignment);
        $reviewedWorkerIds = $session->ratings
            ->filter(static fn ($rating): bool => (string) ($rating->rating_type?->value ?? $rating->rating_type)
                === WorkerCustomerRatingType::CustomerToWorker->value)
            ->pluck('worker_id')
            ->map(static fn ($workerId): int => (int) $workerId)
            ->unique()
            ->values();
        $reviewableWorkerIds = $acceptedAssignments
            ->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => (string) ($assignment->status?->value ?? $assignment->status) === 'completed')
            ->pluck('worker_id')
            ->map(static fn ($workerId): int => (int) $workerId)
            ->diff($reviewedWorkerIds)
            ->values();
        $activeDispute = $session->disputes->first(static function (Dispute $dispute): bool {
            $disputeStatus = $dispute->status instanceof DisputeStatus
                ? $dispute->status
                : DisputeStatus::tryFrom((string) $dispute->status);

            return $disputeStatus !== null && ! $disputeStatus->isTerminal();
        });
        $canOpenDispute = $isCustomerView
            && in_array($status, [
                CleaningBookingSessionStatus::Completed->value,
                CleaningBookingSessionStatus::Cancelled->value,
            ], true)
            && ! $activeDispute instanceof Dispute;
        $paymentStatus = in_array($status, [
            CleaningBookingSessionStatus::Cancelled->value,
            CleaningBookingSessionStatus::Skipped->value,
            CleaningBookingSessionStatus::Superseded->value,
        ], true)
            ? 'not_required'
            : ((string) ($session->payment_status ?: 'pending'));
        $lateGraceMinutes = max(0, (int) config('cleaning_attendance.late_grace_minutes', 15));
        $noTravelGraceMinutes = max($lateGraceMinutes, (int) config('cleaning_attendance.no_travel_grace_minutes', 30));
        $minutesPastStart = $startsAt !== null && $startsAt->lte($now)
            ? (int) floor($startsAt->diffInMinutes($now))
            : 0;
        $attendanceEligibleAssignments = $session->workerAssignments
            ->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && $assignment->started_travel_at === null
                && $assignment->arrived_at === null
                && $assignment->work_started_at === null)
            ->values();
        $lateWorkerIds = $minutesPastStart >= $lateGraceMinutes
            ? $attendanceEligibleAssignments->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()
            : collect();
        $noTravelWorkerIds = $minutesPastStart >= $noTravelGraceMinutes
            ? $attendanceEligibleAssignments->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()
            : collect();
        $reportableLateWorkerIds = $minutesPastStart >= $lateGraceMinutes
            ? $attendanceEligibleAssignments->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->late_reported_at === null)
                ->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()
            : collect();
        $reportableNoTravelWorkerIds = $minutesPastStart >= $noTravelGraceMinutes
            ? $attendanceEligibleAssignments->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->no_travel_reported_at === null)
                ->pluck('worker_id')->map(static fn ($id): int => (int) $id)->values()
            : collect();
        $attendanceIncidents = $session->workerAssignments
            ->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->late_reported_at !== null
                || $assignment->no_travel_reported_at !== null)
            ->map(function (CleaningBookingSessionWorkerAssignment $assignment): array {
                $worker = $assignment->worker;
                $user = $worker?->user;

                return [
                    'workerId' => (int) $assignment->worker_id,
                    'workerName' => $worker?->first_name ?: $user?->name,
                    'lateReportedAt' => $assignment->late_reported_at?->toIso8601String(),
                    'noTravelReportedAt' => $assignment->no_travel_reported_at?->toIso8601String(),
                    'action' => $assignment->attendance_action,
                    'resolvedAt' => $assignment->attendance_resolved_at?->toIso8601String(),
                    'note' => $assignment->attendance_note,
                ];
            })->values();
        $canUseAttendanceActions = $isCustomerView
            && (string) $session->session_type === CleaningBookingSession::TYPE_RECURRING_CLEANING
            && in_array($status, [
                CleaningBookingSessionStatus::Scheduled->value,
                CleaningBookingSessionStatus::WorkerAssigned->value,
            ], true)
            && ! $session->isTerminal();
        $allowedAttendanceActions = [];
        $attendanceActionWorkerIds = [
            CleaningBookingSessionAttendanceService::ACTION_WAIT => [],
            CleaningBookingSessionAttendanceService::ACTION_REPLACE => [],
            CleaningBookingSessionAttendanceService::ACTION_CANCEL => [],
        ];

        if ($canUseAttendanceActions && $lateWorkerIds->isNotEmpty()) {
            $allowedAttendanceActions[] = CleaningBookingSessionAttendanceService::ACTION_WAIT;
            $attendanceActionWorkerIds[CleaningBookingSessionAttendanceService::ACTION_WAIT] = $lateWorkerIds->all();
        }
        if ($canUseAttendanceActions && $noTravelWorkerIds->isNotEmpty()) {
            $allowedAttendanceActions[] = CleaningBookingSessionAttendanceService::ACTION_REPLACE;
            $allowedAttendanceActions[] = CleaningBookingSessionAttendanceService::ACTION_CANCEL;
            $attendanceActionWorkerIds[CleaningBookingSessionAttendanceService::ACTION_REPLACE] = $noTravelWorkerIds->all();
            $attendanceActionWorkerIds[CleaningBookingSessionAttendanceService::ACTION_CANCEL] = $noTravelWorkerIds->all();
        }

        return [
            'id' => (int) $session->id,
            'sessionId' => (int) $session->id,
            'sequence' => (int) $session->sequence,
            'sessionType' => $session->session_type,
            'calculationMode' => $session->calculation_mode,
            'date' => $session->scheduled_date?->format('Y-m-d'),
            'scheduledDate' => $session->scheduled_date?->format('Y-m-d'),
            'time' => (string) $session->scheduled_time,
            'scheduledTime' => (string) $session->scheduled_time,
            'startsAt' => $startsAt?->toIso8601String(),
            'endsAt' => $endsAt?->toIso8601String(),
            'hours' => (float) $session->duration_hours,
            'durationHours' => (float) $session->duration_hours,
            'status' => $status,
            'statusLabel' => $session->status?->label(),
            'isToday' => $startsAt?->isSameDay($now) ?? false,
            'isPast' => $endsAt?->lt($now) ?? false,
            'canStartTravel' => $canStartTravel,
            'canStart' => $canStartTravel,
            'canArrive' => $canArrive,
            'canStartWork' => $canStartWork,
            'canComplete' => $canComplete,
            'canConfirmStartVerification' => $canConfirmStartVerification,
            'canConfirmCompletion' => $canConfirmCompletion,
            'canSendSos' => $canSendSos,
            'canExtend' => $canRequestOpenTimeExtension,
            'canRequestOpenTimeExtension' => $canRequestOpenTimeExtension,
            'canRequestOpenTimeEnd' => $canRequestOpenTimeEnd,
            'canDecideOpenTimeEnd' => $canDecideOpenTimeEnd,
            'openTime' => $openTime,
            'canCancel' => $canCancel,
            'canSkip' => $canSkip,
            'canReschedule' => $isCustomerView && $canReschedule,
            'coverageStatus' => $session->coverage_status?->value ?? (string) $session->coverage_status,
            'coverageStatusLabel' => $session->coverage_status?->label(),
            'requiredWorkers' => $session->requiredWorkerCount(),
            'acceptedWorkers' => $session->acceptedWorkerCount(),
            'remainingWorkers' => $session->remainingWorkerCount(),
            'isFullyCovered' => $session->isFullyCovered(),
            'canReportLate' => $canUseAttendanceActions && $reportableLateWorkerIds->isNotEmpty(),
            'canReportNoTravel' => $canUseAttendanceActions && $reportableNoTravelWorkerIds->isNotEmpty(),
            'lateWorkerIds' => $lateWorkerIds->all(),
            'noTravelWorkerIds' => $noTravelWorkerIds->all(),
            'reportableLateWorkerIds' => $reportableLateWorkerIds->all(),
            'reportableNoTravelWorkerIds' => $reportableNoTravelWorkerIds->all(),
            'allowedAttendanceActions' => $allowedAttendanceActions,
            'attendanceActionWorkerIds' => $attendanceActionWorkerIds,
            'attendance' => [
                'lateGraceMinutes' => $lateGraceMinutes,
                'noTravelGraceMinutes' => $noTravelGraceMinutes,
                'minutesPastStart' => $minutesPastStart,
                'allowedActions' => $allowedAttendanceActions,
                'actionWorkerIds' => $attendanceActionWorkerIds,
                'incidents' => $attendanceIncidents->all(),
            ],
            'paymentStatus' => $paymentStatus,
            'paymentSettledAt' => $session->payment_settled_at?->toIso8601String(),
            'payment' => [
                'status' => $paymentStatus,
                'amount' => (float) $session->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                'settledAt' => $session->payment_settled_at?->toIso8601String(),
                'isInternalSettlement' => true,
            ],
            'canReview' => $isCustomerView && $status === CleaningBookingSessionStatus::Completed->value && $reviewableWorkerIds->isNotEmpty(),
            'hasReview' => $reviewedWorkerIds->isNotEmpty(),
            'reviewedWorkerIds' => $reviewedWorkerIds->all(),
            'reviewableWorkerIds' => $reviewableWorkerIds->all(),
            'canOpenDispute' => $canOpenDispute,
            'hasOpenDispute' => $activeDispute instanceof Dispute,
            'disputeId' => $activeDispute instanceof Dispute ? (int) $activeDispute->id : null,
            'disputeStatus' => $activeDispute instanceof Dispute
                ? ($activeDispute->status?->value ?? (string) $activeDispute->status)
                : null,
            'pricing' => [
                'basePrice' => (float) $session->base_price,
                'addonsTotal' => (float) $session->addons_total,
                'materialsTotal' => (float) $session->materials_total,
                'specialServicesTotal' => (float) $session->special_services_total,
                'travelFee' => (float) $session->travel_fee,
                'travelDistanceKm' => $session->travel_distance_km !== null ? (float) $session->travel_distance_km : null,
                'adminMargin' => (float) $session->admin_margin_amount,
                'extensionFeeTotal' => (float) $session->extension_fee_total,
                'cancellationFee' => (float) $session->cancellation_fee,
                'totalPrice' => (float) $session->total_price,
                'isPricingFinal' => (bool) $session->is_pricing_final,
                'currency' => (string) config('app.currency', 'SYP'),
                'snapshot' => $session->pricing_snapshot,
            ],
            'startedTravelAt' => $session->started_travel_at?->toIso8601String(),
            'arrivedAt' => $session->arrived_at?->toIso8601String(),
            'customerConfirmedAt' => $session->customer_confirmed_at?->toIso8601String(),
            'workStartedAt' => $session->work_started_at?->toIso8601String(),
            'workFinishedAt' => $session->work_finished_at?->toIso8601String(),
            'cancelledAt' => $session->cancelled_at?->toIso8601String(),
            'cancellationReason' => $session->cancellation_reason,
            'cancelledByRole' => $session->cancelled_by_role,
            'skippedAt' => $session->skipped_at?->toIso8601String(),
            'skipReason' => $session->skip_reason,
            'workerAssignments' => $acceptedAssignments
                ->map(fn (CleaningBookingSessionWorkerAssignment $assignment): array => $this->assignmentPayload($assignment))
                ->values()
                ->all(),
            'myWorkerAssignment' => $myAssignmentPayload,
            'myAssignment' => $myAssignmentPayload,
            'workerAssignmentState' => $myAssignmentPayload,
        ];
    }

    /** @return array<string, mixed>|null */
    private function openTimePayload(CleaningBookingSession $session): ?array
    {
        if ((string) $session->session_type !== CleaningBookingSession::TYPE_OPEN_TIME) {
            return null;
        }

        return $this->openTimeBilling->sessionPresentation($session);
    }

    private function canPauseRecurringSession(CleaningBookingSession $session): bool
    {
        if ((string) $session->session_type !== CleaningBookingSession::TYPE_RECURRING_CLEANING) {
            return false;
        }

        if (! in_array($this->status($session), [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            return false;
        }

        $startsAt = $session->startsAt();
        if (
            $startsAt === null
            || ! $startsAt->isFuture()
            || $session->started_travel_at !== null
            || $session->work_started_at !== null
        ) {
            return false;
        }

        return ! $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && $assignment->started_travel_at !== null,
        );
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(CleaningBookingSessionWorkerAssignment $assignment): array
    {
        $worker = $assignment->worker;
        $user = $worker?->user;

        return [
            'id' => (int) $assignment->id,
            'workerId' => (int) $assignment->worker_id,
            'workerName' => $worker?->first_name ?: $user?->name,
            'status' => $assignment->status?->value ?? (string) $assignment->status,
            'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
            'lateReportedAt' => $assignment->late_reported_at?->toIso8601String(),
            'noTravelReportedAt' => $assignment->no_travel_reported_at?->toIso8601String(),
            'attendanceAction' => $assignment->attendance_action,
            'attendanceResolvedAt' => $assignment->attendance_resolved_at?->toIso8601String(),
            'attendanceNote' => $assignment->attendance_note,
            'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),
            'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
            'locationUpdatedAt' => $assignment->location_updated_at?->toIso8601String(),
            'lastLatitude' => $assignment->last_latitude !== null ? (float) $assignment->last_latitude : null,
            'lastLongitude' => $assignment->last_longitude !== null ? (float) $assignment->last_longitude : null,
            'startApprovedAt' => $assignment->start_approved_at?->toIso8601String(),
            'workStartedAt' => $assignment->work_started_at?->toIso8601String(),
            'workFinishedAt' => $assignment->work_finished_at?->toIso8601String(),
            'workerCompletionMessage' => $assignment->worker_completion_message,
            'serviceShareAmount' => (float) $assignment->service_share_amount,
            'travelFee' => (float) $assignment->travel_fee,
            'adminMarginAmount' => (float) $assignment->admin_margin_amount,
            'workerAmount' => (float) $assignment->worker_amount,
            'grossAmount' => round((float) $assignment->service_share_amount + (float) $assignment->travel_fee, 2),
            'netAmount' => (float) $assignment->worker_amount,
            'currency' => (string) ($assignment->currency ?: config('app.currency', 'SYP')),
        ];
    }

    /** @return array<string, mixed> */
    private function singleDayFallback(CleaningBooking $booking): array
    {
        $hours = (float) ($booking->total_hours ?: $booking->estimated_hours ?: 0);
        $date = $booking->scheduled_date?->format('Y-m-d');
        $time = (string) $booking->scheduled_time;
        $start = null;

        if ($date !== null && $time !== '') {
            try {
                $start = CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
            } catch (Throwable) {
                $start = null;
            }
        }

        $status = $booking->status?->value ?? (string) $booking->status;

        return [
            'mode' => 'single_day',
            'isRecurring' => false,
            'isPaused' => false,
            'workerScope' => $booking->resolvedWorkerScope(),
            'specificWorkerIds' => $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC
                ? $booking->specificWorkerIds()
                : [],
            'canPause' => false,
            'canResume' => false,
            'pausedAt' => null,
            'pauseReason' => null,
            'isMultiSession' => false,
            'isMultiDay' => false,
            'sessionsCount' => 1,
            'daysCount' => 1,
            'completedSessionsCount' => $status === 'completed' ? 1 : 0,
            'completedDaysCount' => $status === 'completed' ? 1 : 0,
            'cancelledSessionsCount' => $status === 'cancelled' ? 1 : 0,
            'cancelledDaysCount' => $status === 'cancelled' ? 1 : 0,
            'skippedSessionsCount' => 0,
            'remainingSessionsCount' => in_array($status, ['completed', 'cancelled'], true) ? 0 : 1,
            'remainingDaysCount' => in_array($status, ['completed', 'cancelled'], true) ? 0 : 1,
            'totalHours' => $hours,
            'firstDate' => $date,
            'lastDate' => $date,
            'nextSession' => null,
            'sessions' => [[
                'id' => null,
                'sessionId' => null,
                'sequence' => 1,
                'date' => $date,
                'scheduledDate' => $date,
                'time' => $time,
                'scheduledTime' => $time,
                'startsAt' => $start?->toIso8601String(),
                'endsAt' => $start?->addMinutes(max(1, (int) ceil(max($hours, 1.0) * 60)))->toIso8601String(),
                'hours' => $hours,
                'durationHours' => $hours,
                'status' => $status,
                'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            ]],
        ];
    }

    private function eventScheduleCanReschedule(CleaningBooking $booking, $sessions, ?Worker $viewerWorker): bool
    {
        if (
            $viewerWorker instanceof Worker
            || (string) $booking->property_type !== 'event_assistance'
            || ($booking->status?->value ?? (string) $booking->status) !== 'pending'
        ) {
            return false;
        }

        if ($booking->workerAssignments()
            ->whereIn('status', \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists()) {
            return false;
        }

        foreach ($sessions as $session) {
            if (! $session instanceof CleaningBookingSession) {
                continue;
            }

            if (
                $this->status($session) !== CleaningBookingSessionStatus::Scheduled->value
                || $session->started_travel_at !== null
                || $session->arrived_at !== null
                || $session->customer_confirmed_at !== null
                || $session->work_started_at !== null
                || $session->work_finished_at !== null
                || $session->workerAssignments->contains(
                    static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isAccepted(),
                )
            ) {
                return false;
            }
        }

        return $sessions->isNotEmpty();
    }

    private function status(CleaningBookingSession $session): string
    {
        return $session->status?->value ?? (string) $session->status;
    }
}
