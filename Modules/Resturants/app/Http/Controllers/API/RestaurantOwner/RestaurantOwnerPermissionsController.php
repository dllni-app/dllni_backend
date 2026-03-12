<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantRole;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Spatie\Permission\Models\Permission;

final class RestaurantOwnerPermissionsController
{
    public function __invoke(RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();

        $roles = RestaurantRole::query()
            ->with('permissions')
            ->where('restaurant_id', $restaurant->id)
            ->get()
            ->map(static function (RestaurantRole $role): array {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'permissionIds' => $role->permissions->pluck('id')->values()->all(),
                    'permissions' => $role->permissions->map(static fn (Permission $permission): array => [
                        'id' => $permission->id,
                        'name' => $permission->name,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'roles' => $roles,
            ],
        ]);
    }
}

