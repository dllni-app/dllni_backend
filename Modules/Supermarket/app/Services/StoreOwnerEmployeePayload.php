<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Modules\Supermarket\Models\SmStoreStaff;

final class StoreOwnerEmployeePayload
{
    public static function make(SmStoreStaff $staff): array
    {
        $permissions = $staff->user?->permissions ?? collect();

        return [
            'id' => $staff->id,
            'storeId' => $staff->store_id,
            'userId' => $staff->user_id,
            'isActive' => (bool) $staff->is_active,
            'user' => [
                'id' => $staff->user?->id,
                'name' => $staff->user?->name,
                'email' => $staff->user?->email,
                'phone' => $staff->user?->phone,
                'profileImageUrl' => $staff->user?->getFirstMediaUrl('primary-image') ?: null,
            ],
            'permissionIds' => $permissions->pluck('id')->values()->all(),
            'effectivePermissions' => $permissions->pluck('name')->values()->all(),
            'createdAt' => $staff->created_at?->toDateTimeString(),
            'updatedAt' => $staff->updated_at?->toDateTimeString(),
        ];
    }
}
