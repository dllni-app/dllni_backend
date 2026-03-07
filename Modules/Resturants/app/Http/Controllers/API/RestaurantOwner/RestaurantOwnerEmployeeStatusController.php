<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerEmployeeStatusRequest;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeStatusController
{
    public function __invoke(
        OwnerEmployeeStatusRequest $request,
        RestaurantStaff $restaurant_staff,
        RestaurantOwnerContext $context
    ): JsonResponse {
        $context->ensureOwnedStaff($restaurant_staff);

        $restaurant_staff->update([
            'is_active' => (bool) $request->validated('isActive'),
        ]);

        return response()->json([
            'data' => [
                'id' => $restaurant_staff->id,
                'isActive' => (bool) $restaurant_staff->is_active,
            ],
            'message' => 'Employee status updated successfully.',
        ]);
    }
}
