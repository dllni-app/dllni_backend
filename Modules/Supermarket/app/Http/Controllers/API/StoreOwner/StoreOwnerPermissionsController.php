<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

final class StoreOwnerPermissionsController
{
    public function __invoke(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('group', 'supermarket_owner')
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
