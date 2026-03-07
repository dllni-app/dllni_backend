<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeIndexController
{
    public function __invoke(RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();

        $employees = RestaurantStaff::query()
            ->with(['user', 'role.permissions'])
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (RestaurantStaff $staff): array => [
                'id' => $staff->id,
                'restaurantId' => $staff->restaurant_id,
                'userId' => $staff->user_id,
                'restaurantRoleId' => $staff->restaurant_role_id,
                'isActive' => (bool) $staff->is_active,
                'user' => [
                    'id' => $staff->user?->id,
                    'name' => $staff->user?->name,
                    'email' => $staff->user?->email,
                    'phone' => $staff->user?->phone,
                ],
                'role' => [
                    'id' => $staff->role?->id,
                    'name' => $staff->role?->name,
                    'slug' => $staff->role?->slug,
                ],
                'effectivePermissions' => $staff->role?->permissions?->pluck('name')->values()->all() ?? [],
                'createdAt' => $staff->created_at?->toDateTimeString(),
                'updatedAt' => $staff->updated_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $employees]);
    }
}
