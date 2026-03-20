<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\Response;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeDestroyController
{
    public function __invoke(
        RestaurantStaff $restaurant_staff,
        RestaurantOwnerContext $context
    ): Response {
        $context->ensureOwnedStaff($restaurant_staff);
        $restaurant_staff->delete();

        return response()->noContent();
    }
}
