<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Resturants\Data\RestaurantRoleData;
use Modules\Resturants\Models\RestaurantRole;

final class RestaurantRoleService
{
    public function store(RestaurantRoleData $data, ?array $permissionIds = null): RestaurantRole
    {
        return DB::transaction(static function () use ($data, $permissionIds) {
            $slug = $data->slug ?? Str::slug($data->name ?? 'role');

            $role = RestaurantRole::create([
                'restaurant_id' => $data->restaurantId,
                'name' => $data->name,
                'slug' => $slug,
            ]);

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }

            return $role;
        });
    }

    public function update(RestaurantRoleData $data, RestaurantRole $role, ?array $permissionIds = null): RestaurantRole
    {
        return DB::transaction(static function () use ($data, $role, $permissionIds) {
            tap($role)->update($data->onlyModelAttributes());

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }

            return $role;
        });
    }
}
