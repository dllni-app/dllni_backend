<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderReviewRequest;
use Modules\User\Services\EventAssistanceReviewService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderReviewController
{
    public function __invoke(
        UserCleaningOrderReviewRequest $request,
        int $order,
        UserCleaningOrderService $service,
        EventAssistanceReviewService $eventReviewService,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $validated = $request->validated();

        if ((string) $model->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            $eventReviewService->submit($model, $validated);
        } else {
            if (! isset($validated['workerId'])) {
                throw ValidationException::withMessages([
                    'workerId' => ['A worker is required when reviewing a regular cleaning booking.'],
                ]);
            }

            if (! isset($validated['rating'])) {
                throw ValidationException::withMessages([
                    'rating' => ['A rating is required when reviewing a regular cleaning booking.'],
                ]);
            }

            $service->submitReview($model, $validated);
        }

        return response()->json([
            'data' => ['ok' => true],
            'message' => __('Review submitted successfully.'),
        ]);
    }
}
