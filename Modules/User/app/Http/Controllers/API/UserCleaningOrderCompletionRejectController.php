<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;
use Modules\Cleaning\Services\CleaningBookingWorkerCompletionService;
use Modules\User\Http\Requests\UserCleaningOrderCompletionRejectRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningOrderCompletionRejectController
{
    public function __invoke(
        UserCleaningOrderCompletionRejectRequest $request,
        int $order,
        CleaningBookingWorkerCompletionService $service,
        CleaningBookingSessionLifecycleService $sessionLifecycle,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $sessionsCount = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $model->id)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->count();

        if ($sessionsCount > 1) {
            $pendingSessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $model->id)
                ->where('status', CleaningBookingSessionStatus::AwaitingCustomerCompletion->value)
                ->orderBy('sequence')
                ->get();

            if ($pendingSessions->count() > 1) {
                throw ValidationException::withMessages([
                    'status' => ['More than one session is waiting for completion rejection. Reject the selected session.'],
                ]);
            }

            if ($pendingSessions->count() === 1) {
                $sessionLifecycle->rejectCompletion(
                    $model,
                    $pendingSessions->first(),
                    (int) $request->user()->id,
                    $request->completionRejectionMessage(),
                );
                $updated = $model->fresh() ?? $model;
            } else {
                $updated = $service->reject(
                    booking: $model,
                    message: $request->completionRejectionMessage(),
                    workerId: $request->targetWorkerId(),
                    assignmentId: $request->targetAssignmentId(),
                );
            }
        } else {
            $updated = $service->reject(
                booking: $model,
                message: $request->completionRejectionMessage(),
                workerId: $request->targetWorkerId(),
                assignmentId: $request->targetAssignmentId(),
            );
        }
        $updated->load(['worker.user', 'workerAssignments.worker.user', 'rooms.assignedWorker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return UserCleaningBookingResource::make($updated)->additional([
            'message' => __('Completion rejection sent successfully.'),
        ])->response();
    }
}
