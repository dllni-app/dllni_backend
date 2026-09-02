<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
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

        return [
            'mode' => $sessions->count() > 1 ? 'multi_session' : 'single_session',
            'isMultiSession' => $sessions->count() > 1,
            // Kept for backward compatibility with the legacy multiday Flutter work.
            'isMultiDay' => $sessions->count() > 1,
            'sessionsCount' => $sessions->count(),
            'daysCount' => $sessions->count(),
            'completedSessionsCount' => $completed,
            'completedDaysCount' => $completed,
            'cancelledSessionsCount' => $cancelled,
            'cancelledDaysCount' => $cancelled,
            'skippedSessionsCount' => $skipped,
            'totalHours' => round((float) $sessions
                ->reject(fn (CleaningBookingSession $session): bool => in_array($this->status($session), [
                    CleaningBookingSessionStatus::Cancelled->value,
                    CleaningBookingSessionStatus::Skipped->value,
                ], true))
                ->sum('duration_hours'), 2),
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
        $startsAt = $session->startsAt();
        $endsAt = $session->endsAt();

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
            'status' => $this->status($session),
            'statusLabel' => $session->status?->label(),
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
                'adminMargin' => (float) $session->admin_margin_amount,
                'extensionFeeTotal' => (float) $session->extension_fee_total,
                'cancellationFee' => (float) $session->cancellation_fee,
                'totalPrice' => (float) $session->total_price,
                'isPricingFinal' => (bool) $session->is_pricing_final,
                'snapshot' => $session->pricing_snapshot,
            ],
            'startedTravelAt' => $session->started_travel_at?->toIso8601String(),
            'arrivedAt' => $session->arrived_at?->toIso8601String(),
            'customerConfirmedAt' => $session->customer_confirmed_at?->toIso8601String(),
            'workStartedAt' => $session->work_started_at?->toIso8601String(),
            'workFinishedAt' => $session->work_finished_at?->toIso8601String(),
            'cancelledAt' => $session->cancelled_at?->toIso8601String(),
            'cancellationReason' => $session->cancellation_reason,
            'skippedAt' => $session->skipped_at?->toIso8601String(),
            'skipReason' => $session->skip_reason,
            'workerAssignments' => $acceptedAssignments
                ->map(fn (CleaningBookingSessionWorkerAssignment $assignment): array => $this->assignmentPayload($assignment))
                ->values()
                ->all(),
            'myWorkerAssignment' => $myAssignment instanceof CleaningBookingSessionWorkerAssignment
                ? $this->assignmentPayload($myAssignment)
                : null,
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
            'workStartedAt' => $assignment->work_started_at?->toIso8601String(),
            'workFinishedAt' => $assignment->work_finished_at?->toIso8601String(),
            'serviceShareAmount' => (float) $assignment->service_share_amount,
            'travelFee' => (float) $assignment->travel_fee,
            'adminMarginAmount' => (float) $assignment->admin_margin_amount,
            'workerAmount' => (float) $assignment->worker_amount,
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

        return [
            'mode' => 'single_day_legacy',
            'isMultiSession' => false,
            'isMultiDay' => false,
            'sessionsCount' => 1,
            'daysCount' => 1,
            'completedSessionsCount' => $booking->status?->value === 'completed' ? 1 : 0,
            'completedDaysCount' => $booking->status?->value === 'completed' ? 1 : 0,
            'cancelledSessionsCount' => $booking->status?->value === 'cancelled' ? 1 : 0,
            'cancelledDaysCount' => $booking->status?->value === 'cancelled' ? 1 : 0,
            'skippedSessionsCount' => 0,
            'totalHours' => $hours,
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
                'status' => $booking->status?->value ?? (string) $booking->status,
                'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            ]],
        ];
    }

    private function status(CleaningBookingSession $session): string
    {
        return $session->status?->value ?? (string) $session->status;
    }
}
