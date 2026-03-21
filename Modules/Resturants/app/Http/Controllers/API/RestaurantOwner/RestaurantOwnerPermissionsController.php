<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

final class RestaurantOwnerPermissionsController
{
    public function __invoke(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('group', 'restaurant_owner')
            ->orderBy('name')
            ->get()
            ->map(static function (Permission $permission): array {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'description' => $permission->description,
                    'group' => $permission->group,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'permissions' => $permissions,
            ],
        ]);
    }
}
