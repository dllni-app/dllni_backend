<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderCompletionExtendTimeRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderCompletionExtendTimeController
{
    public function __invoke(UserCleaningOrderCompletionExtendTimeRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $result = $service->requestCompletionExtension(
            booking: $model,
            additionalMinutes: (int) $request->validated('additionalMinutes'),
            customerMessage: $request->customerMessage(),
        );
        $updated = $result['booking'];

        $updated->load(['worker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return CleaningBookingResource::make($updated)->additional([
            'message' => __('Extension request sent successfully.'),
            'extensionPricing' => $result['extensionPricing'],
        ])->response();
    }
}
