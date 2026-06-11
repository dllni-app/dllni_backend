<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderRoomAssignmentsRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderRoomAssignmentsController
{
    public function __invoke(UserCleaningOrderRoomAssignmentsRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $updated = $service->assignRoomAssignments($model, $request->validated('assignments'));

        $updated->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);

        return response()->json([
            'order' => CleaningBookingResource::make($updated),
        ]);
    }
}
