<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingWorkerCompletionService;
use Modules\User\Http\Requests\UserCleaningOrderCompletionRejectRequest;

final class UserCleaningOrderCompletionRejectController
{
    public function __invoke(UserCleaningOrderCompletionRejectRequest $request, int $order, CleaningBookingWorkerCompletionService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $updated = $service->reject($model, $request->completionRejectionMessage());
        $updated->load(['worker.user', 'workerAssignments.worker.user', 'rooms.assignedWorker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return CleaningBookingResource::make($updated)->additional([
            'message' => __('Completion rejection sent successfully.'),
        ])->response();
    }
}
