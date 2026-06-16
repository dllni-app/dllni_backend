<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderCancelRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderCancelController
{
    public function __invoke(UserCleaningOrderCancelRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $cancelled = $service->cancel($model, $request->validated('reason'));
        $cancelled->load(['worker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return response()->json([
            'order' => CleaningBookingResource::make($cancelled),
        ]);
    }
}
