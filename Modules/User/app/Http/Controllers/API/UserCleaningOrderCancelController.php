<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\CleaningFinancialSetting;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Modules\User\Http\Requests\UserCleaningOrderCancelRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderCancelController
{
    private const ARRIVAL_CANCEL_STATUSES = [
        CleaningBookingStatus::AwaitingStartVerification,
        CleaningBookingStatus::AwaitingWorkerStartConfirmation,
    ];

    public function __invoke(
        UserCleaningOrderCancelRequest $request,
        int $order,
        UserCleaningOrderService $service,
        CleaningLifecycleNotificationService $lifecycleNotifications,
        CleaningBookingSessionLifecycleService $sessionLifecycle,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->with('sessions')
            ->findOrFail($order);

        $reason = $request->validated('reason');
        $fee = CleaningFinancialSetting::currentUserCancellationFee();

        if ($model->isEventAssistanceBooking() && $model->sessions->isNotEmpty()) {
            $cancelled = $sessionLifecycle->cancelRemainingSessions(
                $model,
                'customer',
                $reason,
                $fee,
            );

            $cancelled->forceFill([
                'cancelled_by_role' => $cancelled->status === CleaningBookingStatus::Cancelled ? 'customer' : null,
                'cancellation_reason' => $cancelled->status === CleaningBookingStatus::Cancelled ? $reason : null,
                'cancelled_at' => $cancelled->status === CleaningBookingStatus::Cancelled ? ($cancelled->cancelled_at ?? now()) : null,
            ])->saveQuietly();

            foreach ($cancelled->acceptedWorkerAssignments()->with('worker.user')->get() as $assignment) {
                $lifecycleNotifications->notifyWorkerById(
                    booking: $cancelled,
                    workerId: (int) $assignment->worker_id,
                    canonicalType: 'cleaning.booking.order_cancelled',
                    action: 'customer_cancelled_remaining_sessions',
                    actorRole: 'customer',
                    occurredAt: now()->toIso8601String(),
                    extraData: [
                        'remainingSessionsCancelled' => true,
                        'completedSessionsCount' => $cancelled->completedSessionsCount(),
                        'remainingSessionsCount' => $cancelled->remainingSessionsCount(),
                    ],
                );
            }
        } else {
            $cancelled = in_array($model->status, self::ARRIVAL_CANCEL_STATUSES, true)
                ? $this->cancelAfterWorkerArrival($model, $reason, $lifecycleNotifications)
                : $service->cancel($model, $reason);

            $cancelled->forceFill([
                'cancelled_by_role' => 'customer',
                'cancellation_fee' => $fee,
            ])->save();
        }

        $cancelled = $cancelled->fresh();
        $cancelled->load([
            'worker.user',
            'workerAssignments.worker.user',
            'sessions.workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);

        return response()->json([
            'order' => UserCleaningBookingResource::make($cancelled),
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
