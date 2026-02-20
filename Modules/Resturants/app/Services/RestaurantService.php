<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\RestaurantData;
use Modules\Resturants\Models\Restaurant;

final class RestaurantService
{
    public function store(RestaurantData $data): Restaurant
    {
        return DB::transaction(static function () use ($data) {
            return Restaurant::create($data->onlyModelAttributes());
        });
    }

    public function update(RestaurantData $data, Restaurant $restaurant): Restaurant
    {
        return DB::transaction(static function () use ($data, $restaurant) {
            tap($restaurant)->update($data->onlyModelAttributes());

            return $restaurant;
        });
    }
}
