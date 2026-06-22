<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Modules\User\Http\Requests\UserCleaningOrderCancelRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderCancelController
{
    public function __invoke(
        UserCleaningOrderCancelRequest $request,
        int $order,
        UserCleaningOrderService $service,
        CleaningLifecycleNotificationService $lifecycleNotifications,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $reason = $request->validated('reason');

        $cancelled = $model->status === CleaningBookingStatus::AwaitingWorkerStartConfirmation
            ? $this->cancelAfterWorkerArrival($model, $reason, $lifecycleNotifications)
            : $service->cancel($model, $reason);

        $cancelled->forceFill(['cancelled_by_role' => 'customer'])->save();
        $cancelled = $cancelled->fresh();
        $cancelled->load(['worker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return response()->json([
            'order' => CleaningBookingResource::make($cancelled),
        ]);
    }

    private function cancelAfterWorkerArrival(
        CleaningBooking $booking,
        ?string $reason,
        CleaningLifecycleNotificationService $lifecycleNotifications,
    ): CleaningBooking {
        $fromStatus = (string) ($booking->status?->value ?? $booking->status);

        $updated = DB::transaction(function () use ($booking, $reason): CleaningBooking {
            $booking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $booking->update([
                'status' => CleaningBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by_role' => 'customer',
            ]);

            return $booking->fresh();
        });

        $this->dispatchTrackingUpdate($updated);
        $lifecycleNotifications->notifyWorker(
            booking: $updated,
            canonicalType: 'cleaning.booking.order_cancelled',
            action: 'customer_cancelled',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->cancelled_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $booking->status?->value,
            'workerId' => $booking->worker_id,
            'assignmentMode' => $booking->resolvedAssignmentMode(),
            'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            'acceptedWorkers' => $booking->acceptedWorkerCount(),
            'remainingWorkers' => $booking->remainingWorkerCount(),
            'startApprovedWorkers' => $booking->startApprovedWorkerCount(),
            'notStartApprovedWorkers' => $booking->notStartApprovedWorkerCount(),
            'isTeamFulfilled' => $booking->isTeamFulfilled(),
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }
}
