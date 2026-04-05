<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderUpdateRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderUpdateController
{
    public function __invoke(UserCleaningOrderUpdateRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $updated = $service->update($model, $request->validated());
        $updated->load(['worker.user', 'timeWarnings', 'disputes', 'services', 'addons', 'billingPolicy']);

        return response()->json([
            'order' => CleaningBookingResource::make($updated),
        ]);
    }
}
