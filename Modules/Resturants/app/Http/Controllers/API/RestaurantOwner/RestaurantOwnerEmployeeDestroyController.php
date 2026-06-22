<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\Response;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeDestroyController
{
    public function __invoke(int|string $employee, RestaurantOwnerContext $context): Response
    {
        $employee = $this->resolveEmployee($employee, $context);
        $employee->delete();

        return response()->noContent();
    }

    private function resolveEmployee(int|string $employee, RestaurantOwnerContext $context): RestaurantStaff
    {
        $restaurant = $context->restaurant();

        $staff = RestaurantStaff::query()
            ->whereKey($employee)
            ->first();

        if ($staff !== null && (int) $staff->restaurant_id === (int) $restaurant->id) {
            return $staff;
        }

        $staffByUserId = RestaurantStaff::query()
            ->where('user_id', $employee)
            ->first();

        if ($staffByUserId !== null) {
            $context->ensureOwnedStaff($staffByUserId);

            return $staffByUserId;
        }

        if ($staff !== null) {
            $context->ensureOwnedStaff($staff);
        }

        abort(404);
    }
}
