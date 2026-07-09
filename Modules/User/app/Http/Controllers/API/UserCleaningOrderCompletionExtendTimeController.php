<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingWorkerCompletionService;
use Modules\User\Http\Requests\UserCleaningOrderCompletionExtendTimeRequest;

final class UserCleaningOrderCompletionExtendTimeController
{
    public function __invoke(UserCleaningOrderCompletionExtendTimeRequest $request, int $order, CleaningBookingWorkerCompletionService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $result = $service->requestExtension(
            booking: $model,
            additionalMinutes: (int) $request->validated('additionalMinutes'),
            customerMessage: $request->customerMessage(),
        );
        $updated = $result['booking'];
        $updated->load(['worker.user', 'workerAssignments.worker.user', 'rooms.assignedWorker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return CleaningBookingResource::make($updated)->additional([
            'message' => __('Extension request sent successfully.'),
            'extensionPricing' => $result['extensionPricing'],
        ])->response();
    }
}
