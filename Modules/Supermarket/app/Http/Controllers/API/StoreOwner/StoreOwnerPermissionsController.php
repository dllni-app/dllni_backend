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
            ->where(function ($query): void {
                $prefixes = [
                    'products.',
                    'orders.',
                    'inventory.',
                    'staff.',
                    'stores.',
                    'offers.',
                    'coupons.',
                ];

                foreach ($prefixes as $prefix) {
                    $query->orWhere('name', 'like', $prefix.'%');
                }

                $query->orWhere('name', 'reports.view');
            })
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->map(static function (Permission $permission): array {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
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
