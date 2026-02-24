<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Resturants\Data\RestaurantRoleData;
use Modules\Resturants\Models\RestaurantRole;

final class RestaurantRoleService
{
    public function store(RestaurantRoleData $data): RestaurantRole
    {
        return DB::transaction(static function () use ($data) {
            $slug = $data->slug ?? Str::slug($data->name ?? 'role');

            return RestaurantRole::create([
                'restaurant_id' => $data->restaurantId,
                'name' => $data->name,
                'slug' => $slug,
            ]);
        });
    }

    public function update(RestaurantRoleData $data, RestaurantRole $role): RestaurantRole
    {
        return DB::transaction(static function () use ($data, $role) {
            tap($role)->update($data->onlyModelAttributes());

            return $role;
        });
    }
}
