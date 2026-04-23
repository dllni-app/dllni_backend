<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderCompletionConfirmRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderCompletionConfirmController
{
    public function __invoke(UserCleaningOrderCompletionConfirmRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $updated = $service->confirmCompletion($model);
        $updated->load(['worker.user', 'timeWarnings', 'disputes', 'services', 'addons', 'billingPolicy']);

        return CleaningBookingResource::make($updated)->additional([
            'message' => __('Completion confirmed successfully.'),
        ])->response();
    }
}
