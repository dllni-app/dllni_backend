<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\User\Http\Requests\UserCleaningOrderStoreRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderStoreController
{
    public function __invoke(UserCleaningOrderStoreRequest $request, UserCleaningOrderService $service): JsonResponse
    {
        $order = $service->store($request->user(), $request->validated());

        $order->load([
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'services',
            'addons',
            'billingPolicy',
        ]);

        return response()->json([
            'order' => CleaningBookingResource::make($order),
        ], 201);
    }
}
