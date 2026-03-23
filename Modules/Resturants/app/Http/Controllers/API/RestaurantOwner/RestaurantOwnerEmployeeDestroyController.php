<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use App\Models\User;
use Illuminate\Http\Response;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeDestroyController
{
    public function __invoke(User $user, RestaurantOwnerContext $context): Response
    {
        /** @var RestaurantStaff $employee */
        $employee = $user->restaurantStaff()->firstOrFail();
        $context->ensureOwnedStaff($employee);

        $employee->delete();

        return response()->noContent();
    }
}
