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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingCreated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;
use Throwable;

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
        $solvencyService = app(WorkerOrderSolvencyService::class);

        $assignmentMode = $booking->resolvedAssignmentMode();
        $rejectedWorkerIds = $booking->rejections()->pluck('worker_id')->map(static fn (mixed $workerId): int => (int) $workerId)->all();
        $acceptedWorkerIds = $booking->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->all();

        if ($assignmentMode === CleaningAssignmentMode::PreferredWorker->value && $booking->preferred_worker_id !== null) {
            if (in_array((int) $booking->preferred_worker_id, $acceptedWorkerIds, true)) {
                return;
            }

            $worker = Worker::query()
                ->whereKey($booking->preferred_worker_id)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('is_suspended')->orWhere('is_suspended', false);
                })
                ->whereNotIn('id', $rejectedWorkerIds)
                ->with(['user', 'deposit'])
                ->first();

            if (! $worker?->user || ! $depositService->isWorkerEligibleForDispatch($worker)) {
                $this->createDispatchAlert(
                    $booking,
                    'preferred_worker_not_eligible',
                    'Preferred worker is not currently eligible for dispatch.',
                    ['preferredWorkerId' => (int) $booking->preferred_worker_id],
                );

                return;
            }

            $solvency = $solvencyService->solvencyPayloadForBooking($worker, $booking);
            if ((bool) $solvency['canReceiveOrder']) {
                $this->notifyWorkerAboutNewOrder($worker, $booking);

                return;
            }

            $this->createDispatchAlert(
                $booking,
                'preferred_worker_insufficient_commission_capacity',
                'Preferred worker cannot cover the platform commission for this booking.',
                ['preferredWorkerId' => (int) $booking->preferred_worker_id, 'solvency' => $solvency],
            );

            return;
        }

        $workers = Worker::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('is_suspended')->orWhere('is_suspended', false);
            })
            ->when(
                $booking->gender_preference instanceof GenderPreference
                    && $booking->gender_preference !== GenderPreference::Any,
                fn ($query) => $query->where('gender', $booking->gender_preference->value)
            )
            ->whereNotIn('id', array_values(array_unique(array_merge($rejectedWorkerIds, $acceptedWorkerIds))))
            ->with(['user', 'deposit'])
            ->limit(50)
            ->get();

        if ($workers->isEmpty()) {
            $this->createDispatchAlert(
                $booking,
                'no_active_workers',
                'No active workers are available for this booking.',
            );

            return;
        }

        $notifiedCount = 0;
        $ineligibleCount = 0;
        $financiallyBlockedCount = 0;
        $lastBlockedPayload = null;

        foreach ($workers as $worker) {
            if (! $worker->user || ! $depositService->isWorkerEligibleForDispatch($worker)) {
                $ineligibleCount++;
                continue;
            }

            $solvency = $solvencyService->solvencyPayloadForBooking($worker, $booking);
            if (! (bool) $solvency['canReceiveOrder']) {
                $financiallyBlockedCount++;
                $lastBlockedPayload = $solvency;

                continue;
            }

            $this->notifyWorkerAboutNewOrder($worker, $booking);
            $notifiedCount++;
        }

        if ($notifiedCount === 0 && $financiallyBlockedCount > 0) {
            $this->createDispatchAlert(
                $booking,
                'no_financially_solvent_workers',
                'No eligible worker has enough balance or allowed negative limit to cover the booking platform commission.',
                [
                    'financiallyBlockedWorkersCount' => $financiallyBlockedCount,
                    'lastBlockedSolvencyPayload' => $lastBlockedPayload,
                ],
            );
        }

        if ($notifiedCount === 0 && $financiallyBlockedCount === 0 && $ineligibleCount > 0) {
            $this->createDispatchAlert(
                $booking,
                'no_dispatch_eligible_workers',
                'Active workers exist, but none are currently eligible for dispatch.',
                ['ineligibleWorkersCount' => $ineligibleCount],
            );
        }
    }

    private function notifyWorkerAboutNewOrder(Worker $worker, CleaningBooking $booking): void
    {
        if (! $worker->user) {
            return;
        }

        try {
            $worker->user->notify(new NewOrderRequestNotification($booking));
        } catch (Throwable $exception) {
            Log::warning('Cleaning new order notification failed.', [
                'booking_id' => $booking->id,
                'worker_id' => $worker->id,
                'user_id' => $worker->user->id,
                'message' => $exception->getMessage(),
            ]);
        }

        $this->broadcastNewOrderToWorker($worker, $booking);
    }

    private function broadcastNewOrderToWorker(Worker $worker, CleaningBooking $booking): void
    {
        try {
            event(new CleaningBookingCreated(
                cleaningBookingId: (int) $booking->id,
                workerId: (int) $worker->id,
                booking: $this->bookingBroadcastPayload($booking),
            ));
        } catch (Throwable $exception) {
            Log::warning('Cleaning new order realtime broadcast failed.', [
                'booking_id' => $booking->id,
                'worker_id' => $worker->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingBroadcastPayload(CleaningBooking $booking): array
    {
        return [
            'id' => (int) $booking->id,
            'bookingId' => (int) $booking->id,
            'cleaningBookingId' => (int) $booking->id,
            'bookingNumber' => (string) $booking->booking_number,
            'booking_number' => (string) $booking->booking_number,
            'status' => $booking->status?->value ?? (string) $booking->status,
            'order_status' => $booking->status?->value ?? (string) $booking->status,
            'scheduledDate' => $booking->scheduled_date?->format('Y-m-d'),
            'scheduled_date' => $booking->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => (string) $booking->scheduled_time,
            'scheduled_time' => (string) $booking->scheduled_time,
            'propertyType' => (string) $booking->property_type,
            'property_type' => (string) $booking->property_type,
            'neighborhoodId' => $booking->neighborhood_id,
            'neighborhood_id' => $booking->neighborhood_id,
            'neighborhoodName' => $booking->neighborhood_name,
            'neighborhood_name' => $booking->neighborhood_name,
            'totalPrice' => (float) ($booking->total_price ?? 0),
            'total_price' => (float) ($booking->total_price ?? 0),
            'numberOfWorkers' => (int) ($booking->number_of_workers ?? 1),
            'number_of_workers' => (int) ($booking->number_of_workers ?? 1),
        ];
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
                    'source' => 'cleaning_order_dispatch',
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
