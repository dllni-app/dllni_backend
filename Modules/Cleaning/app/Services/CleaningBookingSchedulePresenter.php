<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\Carbon;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSchedulePresenter
{
    /** @return array<string, mixed> */
    public function forBooking(CleaningBooking $booking, ?int $workerId = null): array
    {
        $booking->loadMissing(['sessions.workerAssignments.worker.user']);
        $sessions = $booking->sessions->sortBy('sequence')->values();

        if ($sessions->isEmpty()) {
            return $this->legacySchedule($booking);
        }

        $next = $sessions->first(fn (CleaningBookingSession $session): bool => ! in_array($session->status, [
            CleaningBookingSessionStatus::Completed,
            CleaningBookingSessionStatus::Cancelled,
        ], true));
        $first = $sessions->first();
        $last = $sessions->last();

        return [
            'mode' => $sessions->count() > 1 ? 'multi_day' : 'single_day',
            'daysCount' => $sessions->count(),
            'completedDaysCount' => $sessions->where('status', CleaningBookingSessionStatus::Completed)->count(),
            'cancelledDaysCount' => $sessions->where('status', CleaningBookingSessionStatus::Cancelled)->count(),
            'remainingDaysCount' => $sessions->filter(fn (CleaningBookingSession $session): bool => ! in_array($session->status, [CleaningBookingSessionStatus::Completed, CleaningBookingSessionStatus::Cancelled], true))->count(),
            'totalHours' => round((float) $sessions->sum('duration_hours'), 2),
            'firstDate' => $first?->scheduled_date?->toDateString(),
            'lastDate' => $last?->scheduled_date?->toDateString(),
            'nextSession' => $next instanceof CleaningBookingSession ? $this->session($next, $workerId) : null,
            'sessions' => $sessions->map(fn (CleaningBookingSession $session): array => $this->session($session, $workerId))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function session(CleaningBookingSession $session, ?int $workerId = null): array
    {
        $session->loadMissing(['workerAssignments.worker.user']);
        $status = $session->status instanceof CleaningBookingSessionStatus
            ? $session->status
            : CleaningBookingSessionStatus::tryFrom((string) $session->status) ?? CleaningBookingSessionStatus::Scheduled;
        $today = now(config('app.timezone'))->toDateString();
        $date = $session->scheduled_date?->toDateString();
        $workerAssignment = $workerId !== null
            ? $session->workerAssignments->firstWhere('worker_id', $workerId)
            : null;

        return [
            'id' => $session->id,
            'sequence' => (int) $session->sequence,
            'date' => $date,
            'time' => mb_substr((string) $session->scheduled_time, 0, 5),
            'hours' => (float) $session->duration_hours,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'isToday' => $date === $today,
            'isPast' => $date !== null && $date < $today,
            'canStartTravel' => $status === CleaningBookingSessionStatus::WorkerAssigned,
            'canArrive' => in_array($status, [CleaningBookingSessionStatus::WorkerAssigned, CleaningBookingSessionStatus::AwaitingStartVerification], true),
            'canStartWork' => $status === CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation,
            'canComplete' => $status === CleaningBookingSessionStatus::InProgress,
            'canExtend' => $status === CleaningBookingSessionStatus::AwaitingCustomerCompletion,
            'canCancel' => in_array($status, [CleaningBookingSessionStatus::Scheduled, CleaningBookingSessionStatus::WorkerAssigned, CleaningBookingSessionStatus::AwaitingStartVerification], true),
            'startedTravelAt' => $session->started_travel_at?->toIso8601String(),
            'arrivedAt' => $session->arrived_at?->toIso8601String(),
            'customerConfirmedAt' => $session->customer_confirmed_at?->toIso8601String(),
            'workStartedAt' => $session->work_started_at?->toIso8601String(),
            'workFinishedAt' => $session->work_finished_at?->toIso8601String(),
            'cancelledAt' => $session->cancelled_at?->toIso8601String(),
            'cancellationReason' => $session->cancellation_reason,
            'cancelledByRole' => $session->cancelled_by_role,
            'pricing' => [
                'basePrice' => (float) $session->base_price,
                'travelFee' => (float) $session->travel_fee,
                'travelDistanceKm' => $session->travel_distance_km !== null ? (float) $session->travel_distance_km : null,
                'adminMargin' => (float) $session->admin_margin_amount,
                'extensionFeeTotal' => (float) $session->extension_fee_total,
                'cancellationFee' => (float) $session->cancellation_fee,
                'totalPrice' => (float) $session->total_price,
                'isPricingFinal' => (bool) $session->is_pricing_final,
            ],
            'workerAssignmentState' => $workerAssignment instanceof CleaningBookingSessionWorkerAssignment
                ? $this->workerAssignment($workerAssignment)
                : null,
            'workerAssignments' => $session->workerAssignments->map(fn (CleaningBookingSessionWorkerAssignment $assignment): array => $this->workerAssignment($assignment))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function workerAssignment(CleaningBookingSessionWorkerAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'parentAssignmentId' => $assignment->cleaning_booking_worker_assignment_id,
            'workerId' => $assignment->worker_id,
            'workerName' => $assignment->worker?->first_name ?: $assignment->worker?->user?->name,
            'status' => $assignment->status?->value ?? $assignment->status,
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
            'currency' => (string) $assignment->currency,
        ];
    }

    /** @return array<string, mixed> */
    private function legacySchedule(CleaningBooking $booking): array
    {
        $date = $booking->scheduled_date?->toDateString();
        $hours = (float) ($booking->total_hours ?? $booking->estimated_hours ?? 0);
        $session = [
            'id' => null,
            'sequence' => 1,
            'date' => $date,
            'time' => mb_substr((string) $booking->scheduled_time, 0, 5),
            'hours' => $hours,
            'status' => $booking->status?->value ?? (string) $booking->status,
            'statusLabel' => $booking->status?->label() ?? null,
            'isToday' => $date === now(config('app.timezone'))->toDateString(),
            'isPast' => $date !== null && $date < now(config('app.timezone'))->toDateString(),
            'pricing' => [
                'basePrice' => (float) $booking->base_price,
                'travelFee' => (float) $booking->travel_fee,
                'adminMargin' => (float) $booking->admin_margin_amount,
                'extensionFeeTotal' => (float) ($booking->extension_fee_total ?? 0),
                'cancellationFee' => (float) $booking->cancellation_fee,
                'totalPrice' => (float) $booking->total_price,
                'isPricingFinal' => (bool) $booking->is_pricing_final,
            ],
            'workerAssignmentState' => null,
            'workerAssignments' => [],
        ];

        return [
            'mode' => 'single_day',
            'daysCount' => 1,
            'completedDaysCount' => ($booking->status?->value ?? $booking->status) === 'completed' ? 1 : 0,
            'cancelledDaysCount' => ($booking->status?->value ?? $booking->status) === 'cancelled' ? 1 : 0,
            'remainingDaysCount' => in_array(($booking->status?->value ?? $booking->status), ['completed', 'cancelled'], true) ? 0 : 1,
            'totalHours' => $hours,
            'firstDate' => $date,
            'lastDate' => $date,
            'nextSession' => in_array(($booking->status?->value ?? $booking->status), ['completed', 'cancelled'], true) ? null : $session,
            'sessions' => [$session],
        ];
    }
}
