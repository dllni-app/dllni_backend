<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerEmployeePayload;

final class RestaurantOwnerEmployeeIndexController
{
    public function __invoke(RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();

        $employees = RestaurantStaff::query()
            ->with(['user.permissions', 'user.media'])
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (RestaurantStaff $staff): array => RestaurantOwnerEmployeePayload::make($staff))
            ->values()
            ->all();

        return response()->json(['data' => $employees]);
    }
}
