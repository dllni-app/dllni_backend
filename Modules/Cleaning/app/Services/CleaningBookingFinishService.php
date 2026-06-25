<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\SystemAlertStatus;
use App\Models\BookingStatusLog;
use App\Models\Dispute;
use App\Models\SystemAlert;
use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingFinishService
{
    public const DISPUTE_REASON_LABELS = [
        'customer_terms_violation' => 'Customer does not comply with platform terms',
        'financial_or_verbal_dispute' => 'Financial or verbal dispute with customer',
        'force_majeure' => 'Force majeure prevents work completion',
        'other' => 'Other',
    ];

    public function __construct(
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    public function finishSuccessfully(CleaningBooking $booking): CleaningBooking
    {
        $worker = $this->currentWorker();
        $fromStatus = $this->statusValue($booking);

        $updated = DB::transaction(function () use ($booking, $worker): CleaningBooking {
            $booking = $this->lockBooking($booking);
            $this->ensureWorkerCanFinish($booking, $worker);

            $finishedAt = now();

            $booking->forceFill([
                'status' => CleaningBookingStatus::Completed,
                'work_finished_at' => $booking->work_finished_at ?? $finishedAt,
                'timer_stopped_at' => $finishedAt,
                'customer_confirmed_at' => $booking->customer_confirmed_at ?? $finishedAt,
                'worker_completion_message' => null,
                'customer_completion_rejection_message' => null,
                'completion_rejected_at' => null,
            ])->save();

            $this->logStatusChange($booking, CleaningBookingStatus::InProgress->value, CleaningBookingStatus::Completed->value, 'Worker finished the task successfully.');

            return $booking->fresh($this->relations());
        });

        $this->dispatchTrackingUpdate($updated, $fromStatus, 'worker_finished_successfully');
        $this->lifecycleNotifications->notifyCustomer(
            booking: $updated,
            canonicalType: 'cleaning.booking.worker_finished_successfully',
            action: 'worker_finished_successfully',
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $updated->timer_stopped_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    public function openDispute(CleaningBooking $booking, string $reasonType, ?string $reasonNote = null): CleaningBooking
    {
        $worker = $this->currentWorker();
        $fromStatus = $this->statusValue($booking);
        $reasonLabel = self::DISPUTE_REASON_LABELS[$reasonType] ?? self::DISPUTE_REASON_LABELS['other'];

        $updated = DB::transaction(function () use ($booking, $worker, $reasonType, $reasonLabel, $reasonNote): CleaningBooking {
            $booking = $this->lockBooking($booking);
            $this->ensureWorkerCanFinish($booking, $worker);

            $openedAt = now();

            $booking->forceFill([
                'status' => CleaningBookingStatus::UnderDispute,
                'disputed_at' => $openedAt,
                'timer_stopped_at' => $openedAt,
                'work_finished_at' => $booking->work_finished_at ?? $openedAt,
            ])->save();

            $dispute = Dispute::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'ticket_number' => $this->nextTicketNumber($booking),
                'description' => $reasonNote ?? $reasonLabel,
                'category' => $this->mapReasonToCategory($reasonType)->value,
                'status' => DisputeStatus::Open->value,
                'resolution' => null,
                'worker_earnings_frozen' => true,
                'reason_type' => $reasonType,
                'reason_label' => $reasonLabel,
                'reason_note' => $reasonNote,
                'opened_by_worker_id' => $worker->id,
                'opened_by_user_id' => Auth::id(),
                'opened_at' => $openedAt,
            ]);

            SystemAlert::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'alert_type' => AlertType::AnomalyDetected->value,
                'severity' => AlertSeverity::Critical->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => [
                    'source' => 'cleaning_worker_finish_dispute',
                    'alert_type' => 'cleaning_booking_dispute',
                    'dispute_id' => $dispute->id,
                    'worker_id' => $worker->id,
                    'user_id' => Auth::id(),
                    'booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'reason_type' => $reasonType,
                    'reason_label' => $reasonLabel,
                    'reason_note' => $reasonNote,
                    'message' => 'Cleaning booking has been suspended and assigned to admin review.',
                ],
            ]);

            $this->logStatusChange($booking, CleaningBookingStatus::InProgress->value, CleaningBookingStatus::UnderDispute->value, 'Worker opened a cleaning booking dispute: '.$reasonLabel);

            return $booking->fresh($this->relations());
        });

        $this->dispatchTrackingUpdate($updated, $fromStatus, 'worker_opened_dispute');
        $this->lifecycleNotifications->notifyCustomer(
            booking: $updated,
            canonicalType: 'cleaning.booking.under_dispute',
            action: 'worker_opened_dispute',
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $updated->disputed_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
            extraData: [
                'reasonType' => $reasonType,
                'reasonLabel' => $reasonLabel,
                'message' => 'Cleaning booking has been suspended and assigned to admin review.',
            ],
            templateContext: [
                'reason_label' => $reasonLabel,
            ],
        );

        return $updated;
    }

    private function currentWorker(): Worker
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            throw new InvalidArgumentException('User must have an associated worker.');
        }

        return $worker;
    }

    private function lockBooking(CleaningBooking $booking): CleaningBooking
    {
        return CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureWorkerCanFinish(CleaningBooking $booking, Worker $worker): void
    {
        if ($booking->status !== CleaningBookingStatus::InProgress) {
            throw new InvalidArgumentException('Only in-progress cleaning bookings can be finished.');
        }

        $hasWorkerAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ((int) ($booking->worker_id ?? 0) !== (int) $worker->id && ! $hasWorkerAssignment) {
            throw new InvalidArgumentException('Only an assigned worker can finish this cleaning booking.');
        }
    }

    private function mapReasonToCategory(string $reasonType): DisputeCategory
    {
        return match ($reasonType) {
            'customer_terms_violation' => DisputeCategory::Unprofessional,
            'financial_or_verbal_dispute' => DisputeCategory::BillingIssue,
            default => DisputeCategory::Other,
        };
    }

    private function nextTicketNumber(CleaningBooking $booking): string
    {
        return sprintf('CLN-DSP-%s-%s', $booking->id, now()->format('YmdHis'));
    }

    private function logStatusChange(CleaningBooking $booking, string $fromStatus, string $toStatus, string $note): void
    {
        BookingStatusLog::query()->create([
            'booking_id' => $booking->id,
            'booking_type' => $booking->getMorphClass(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }

    private function statusValue(CleaningBooking $booking): string
    {
        return $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking, string $fromStatus, string $action): void
    {
        $status = $this->statusValue($booking);
        $latestDispute = $booking->relationLoaded('disputes') ? $booking->disputes->sortByDesc('id')->first() : null;

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'bookingNumber' => $booking->booking_number,
            'oldStatus' => $fromStatus,
            'status' => $status,
            'newStatus' => $status,
            'action' => $action,
            'workerId' => $booking->worker_id,
            'isTimerRunning' => false,
            'timerStoppedAt' => $booking->timer_stopped_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'disputedAt' => $booking->disputed_at?->toIso8601String(),
            'requiresRefetch' => true,
            'suspendedMessage' => $status === CleaningBookingStatus::UnderDispute->value
                ? 'Cleaning booking has been suspended and assigned to admin review.'
                : null,
            'dispute' => $latestDispute instanceof Dispute ? [
                'id' => $latestDispute->id,
                'status' => $latestDispute->status?->value ?? $latestDispute->status,
                'reasonType' => $latestDispute->reason_type,
                'reasonLabel' => $latestDispute->reason_label,
                'openedAt' => $latestDispute->opened_at?->toIso8601String(),
            ] : null,
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ];
    }
}
