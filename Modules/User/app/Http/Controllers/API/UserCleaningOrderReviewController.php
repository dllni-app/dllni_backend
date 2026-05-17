<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderReviewRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderReviewController
{
    public function __invoke(UserCleaningOrderReviewRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $service->submitReview($model, $request->validated());

        return response()->json([
            'data' => ['ok' => true],
            'message' => __('Review submitted successfully.'),
        ]);
    }
}

