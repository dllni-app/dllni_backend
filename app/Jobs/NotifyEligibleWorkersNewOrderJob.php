<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\GenderPreference;
use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\DepositService;

final class NotifyEligibleWorkersNewOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $cleaningBookingId
    ) {
        // This job is dispatched from model observers that may run inside
        // active DB transactions. Delay queue push until commit so the booking
        // is visible to the worker process.
        $this->afterCommit();
    }

    public function handle(): void
    {
        $booking = CleaningBooking::find($this->cleaningBookingId);
        if (! $booking) {
            return;
        }

        $depositService = app(DepositService::class);

        $bookingDateTime = $this->bookingDateTime($booking);
        $assignmentMode = $booking->resolvedAssignmentMode();
        $rejectedWorkerIds = $booking->rejections()->pluck('worker_id')->map(static fn (mixed $workerId): int => (int) $workerId)->all();
        $acceptedWorkerIds = $booking->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->all();

        if ($booking->neighborhood_id === null) {
            $this->createDispatchAlert(
                $booking,
                'missing_neighborhood',
                'Booking cannot be dispatched because neighborhood is missing.',
            );

            return;
        }

        if ($assignmentMode === CleaningAssignmentMode::PreferredWorker->value && $booking->preferred_worker_id !== null) {
            if (in_array((int) $booking->preferred_worker_id, $acceptedWorkerIds, true)) {
                return;
            }

            $preferredWorker = Worker::query()->find($booking->preferred_worker_id);
            if (! $preferredWorker?->hasActiveCoverageForNeighborhood((int) $booking->neighborhood_id)) {
                $this->createDispatchAlert(
                    $booking,
                    'preferred_worker_outside_neighborhood',
                    'Preferred worker does not cover the booking neighborhood.',
                    ['preferredWorkerId' => (int) $booking->preferred_worker_id],
                );

                return;
            }

            $worker = Worker::query()
                ->whereKey($booking->preferred_worker_id)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('is_suspended')->orWhere('is_suspended', false);
                })
                ->whereHas('user', function ($query): void {
                    $query->where('is_active', true);
                })
                ->whereNotIn('id', $rejectedWorkerIds)
                ->coversNeighborhood((int) $booking->neighborhood_id)
                ->with(['user', 'deposit'])
                ->first();

            if ($worker instanceof Worker && $this->isDispatchable($worker, $bookingDateTime, $depositService)) {
                $worker->user?->notify(new NewOrderRequestNotification($booking));
            }

            return;
        }

        $workers = Worker::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('is_suspended')->orWhere('is_suspended', false);
            })
            ->whereHas('user', function ($query): void {
                $query->where('is_active', true);
            })
            ->when(
                $booking->gender_preference instanceof GenderPreference
                    && $booking->gender_preference !== GenderPreference::Any,
                fn ($query) => $query->where('gender', $booking->gender_preference->value)
            )
            ->whereNotIn('id', array_values(array_unique(array_merge($rejectedWorkerIds, $acceptedWorkerIds))))
            ->coversNeighborhood((int) $booking->neighborhood_id)
            ->with(['user', 'deposit'])
            ->limit(50)
            ->get();

        if ($workers->isEmpty()) {
            $this->createDispatchAlert(
                $booking,
                'no_neighborhood_coverage',
                'No active workers cover the booking neighborhood.',
            );

            return;
        }

        foreach ($workers as $worker) {
            if ($this->isDispatchable($worker, $bookingDateTime, $depositService)) {
                $worker->user?->notify(new NewOrderRequestNotification($booking));
            }
        }
    }

    private function isDispatchable(Worker $worker, ?Carbon $bookingDateTime, DepositService $depositService): bool
    {
        return $worker->user !== null
            && (bool) $worker->user->is_active
            && $this->isWorkerAvailable($worker, $bookingDateTime)
            && $depositService->isWorkerEligibleForDispatch($worker);
    }

    private function isWorkerAvailable(Worker $worker, ?Carbon $bookingDateTime): bool
    {
        if ($bookingDateTime === null) {
            return false;
        }

        return $worker->isAvailableAt($bookingDateTime);
    }

    private function bookingDateTime(CleaningBooking $booking): ?Carbon
    {
        if ($booking->scheduled_date === null || $booking->scheduled_time === null) {
            return null;
        }

        try {
            return Carbon::parse($booking->scheduled_date->format('Y-m-d').' '.trim((string) $booking->scheduled_time), config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createDispatchAlert(CleaningBooking $booking, string $reasonCode, string $message, array $payload = []): void
    {
        SystemAlert::query()->updateOrCreate(
            [
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'alert_type' => AlertType::AnomalyDetected->value,
            ],
            [
                'severity' => AlertSeverity::High->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => array_merge([
                    'source' => 'cleaning_neighborhood_dispatch',
                    'reasonCode' => $reasonCode,
                    'message' => $message,
                    'bookingId' => $booking->id,
                    'neighborhoodId' => $booking->neighborhood_id,
                    'neighborhoodName' => $booking->neighborhood_name,
                ], $payload),
            ],
        );
    }
}
