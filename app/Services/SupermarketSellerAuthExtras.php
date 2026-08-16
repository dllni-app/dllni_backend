<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserModuleType;
use App\Models\User;
use App\Support\SupermarketOwnerPermissionCatalog;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

final class SupermarketSellerAuthExtras
{
    /**
     * @return array<string, mixed>|null
     */
    public static function rolePayload(User $user): ?array
    {
        if ($user->module_type !== UserModuleType::SupermarketSeller) {
            return null;
        }

        if ($user->smStores()->exists()) {
            return [
                'id' => null,
                'name' => 'مالك',
                'slug' => 'owner',
            ];
        }

        if (! $user->smStoreStaff()->exists()) {
            return null;
        }

        return [
            'id' => null,
            'name' => 'موظف',
            'slug' => 'employee',
        ];
    }

    /**
     * @return list<array{id: int, name: string, slug: string|null, description: string|null, group: string|null}>
     */
    public static function permissionsPayload(User $user): array
    {
        if ($user->module_type !== UserModuleType::SupermarketSeller) {
            return [];
        }

        $guardName = config('auth.defaults.guard');

        /** @var Collection<int, Permission> $catalog */
        $catalog = Permission::query()
            ->where('guard_name', $guardName)
            ->where('group', SupermarketOwnerPermissionCatalog::GROUP)
            ->whereIn('name', SupermarketOwnerPermissionCatalog::NAMES)
            ->orderBy('name')
            ->get();

        if ($user->smStores()->exists()) {
            $selected = $catalog;
        } else {
            $assignedIds = $user->getAllPermissions()
                ->where('group', SupermarketOwnerPermissionCatalog::GROUP)
                ->whereIn('name', SupermarketOwnerPermissionCatalog::NAMES)
                ->pluck('id')
                ->all();

            $selected = $catalog->whereIn('id', $assignedIds)->values();
        }

        return $selected
            ->map(static function (Permission $permission): array {
                return [
                    'id' => (int) $permission->id,
                    'name' => (string) $permission->name,
                    'slug' => $permission->slug,
                    'description' => $permission->description,
                    'group' => $permission->group,
                ];
            })
            ->values()
            ->all();
    }
}
