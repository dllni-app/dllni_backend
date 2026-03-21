<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Resturants\Models\RestaurantStaff;
use Spatie\Permission\Models\Permission;

final class RestaurantSellerAuthExtras
{
    /**
     * @return array<string, mixed>|null
     */
    public static function rolePayload(User $user): ?array
    {
        if ($user->module_type !== UserModuleType::RestaurantSeller) {
            return null;
        }

        if ($user->restaurants()->exists()) {
            return [
                'id' => null,
                'name' => 'مالك',
                'slug' => 'owner',
            ];
        }

        $staff = RestaurantStaff::query()
            ->where('user_id', $user->id)
            ->with('role')
            ->first();

        if (! $staff) {
            return null;
        }

        $role = $staff->role;

        if ($role !== null) {
            return [
                'id' => $role->id,
                'name' => $role->name,
            ];
        }

        return [
            'id' => null,
            'name' => 'موظف',
        ];
    }

    /**
     * @return list<array{id: int, name: string, slug: string|null, group: string|null}>
     */
    public static function permissionsPayload(User $user): array
    {
        if ($user->module_type !== UserModuleType::RestaurantSeller) {
            return [];
        }

        $guardName = config('auth.defaults.guard');

        /** @var Collection<int, Permission> $catalog */
        $catalog = Permission::query()
            ->where('guard_name', $guardName)
            ->where('group', 'restaurant_owner')
            ->orderBy('name')
            ->get();

        if ($user->restaurants()->exists()) {
            $selected = $catalog;
        } else {
            $assignedIds = $user->getAllPermissions()
                ->where('group', 'restaurant_owner')
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
