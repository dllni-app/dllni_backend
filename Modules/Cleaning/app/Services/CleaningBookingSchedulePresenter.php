<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSchedulePresenter
{
    /** @return array<string, mixed> */
    public function present(CleaningBooking $booking, ?Worker $viewerWorker = null): array
    {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->with(['workerAssignments.worker.user'])
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
            ->filter(fn (CleaningBookingSession $session): bool => ! $session->isTerminal())
            ->sortBy(fn (CleaningBookingSession $session): string => ($session->startsAt()?->toIso8601String() ?? '9999'))
            ->first();
        $firstDate = $sessions->min('scheduled_date');
        $lastDate = $sessions->max('scheduled_date');

        return [
            // `multi_day` is deliberately preserved because both legacy Flutter
            // clients already understand it. `isMultiSession` is the canonical
            // new meaning and permits the same contract to serve recurring work.
            'mode' => $sessions->count() > 1 ? 'multi_day' : 'single_day',
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
                ? $this->sessionPayload($next, $viewerWorker)
                : null,
            'sessions' => $sessions
                ->map(fn (CleaningBookingSession $session): array => $this->sessionPayload($session, $viewerWorker))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionPayload(CleaningBookingSession $session, ?Worker $viewerWorker): array
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
            && $status === CleaningBookingSessionStatus::InProgress->value;
        $canCancel = $hasMyActiveAssignment && ! $session->isTerminal();

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
            'canExtend' => false,
            'canCancel' => $canCancel,
            'coverageStatus' => $session->coverage_status?->value ?? (string) $session->coverage_status,
            'coverageStatusLabel' => $session->coverage_status?->label(),
            'requiredWorkers' => $session->requiredWorkerCount(),
            'acceptedWorkers' => $session->acceptedWorkerCount(),
            'remainingWorkers' => $session->remainingWorkerCount(),
            'isFullyCovered' => $session->isFullyCovered(),
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
            } catch (\Throwable) {
                $start = null;
            }
        }

        $status = $booking->status?->value ?? (string) $booking->status;

        return [
            'mode' => 'single_day',
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

    private function status(CleaningBookingSession $session): string
    {
        return $session->status?->value ?? (string) $session->status;
    }
}
