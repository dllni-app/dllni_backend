<?php

declare(strict_types=1);

namespace Modules\Resturants\Support;

use Modules\Resturants\Models\RestaurantStaff;

final class RestaurantOwnerEmployeePayload
{
    public static function make(RestaurantStaff $staff): array
    {
        $permissions = $staff->user?->permissions ?? collect();

        return [
            'id' => $staff->id,
            'restaurantId' => $staff->restaurant_id,
            'userId' => $staff->user_id,
            'isActive' => (bool) $staff->is_active,
            'user' => [
                'id' => $staff->user?->id,
                'name' => $staff->user?->name,
                'email' => $staff->user?->email,
                'phone' => $staff->user?->phone,
            ],
            'permissionIds' => $permissions->pluck('id')->values()->all(),
            'effectivePermissions' => $permissions->pluck('name')->values()->all(),
            'createdAt' => $staff->created_at?->toDateTimeString(),
            'updatedAt' => $staff->updated_at?->toDateTimeString(),
        ];
    }
}
