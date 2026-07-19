<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserCouponAvailabilityCheckRequest;
use Modules\User\Services\UserCouponAvailabilityService;

final class UserCouponAvailabilityCheckController
{
    public function __invoke(
        UserCouponAvailabilityCheckRequest $request,
        UserCouponAvailabilityService $couponAvailability,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json([
            'data' => $couponAvailability->check(
                userId: (int) $request->user()->id,
                section: (string) $validated['section'],
                couponCode: (string) $validated['couponCode'],
                context: $validated,
            ),
        ]);
    }
}
