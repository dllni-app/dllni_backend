<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\RestaurantData;
use Modules\Resturants\Models\Restaurant;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class RestaurantService
{
    public function store(RestaurantData $data): Restaurant
    {
        return DB::transaction(function () use ($data) {
            $restaurant = Restaurant::create($data->onlyModelAttributes());
            $this->attachMedia($data, $restaurant, false);

            return $restaurant;
        });
    }

    public function update(RestaurantData $data, Restaurant $restaurant): Restaurant
    {
        return DB::transaction(function () use ($data, $restaurant) {
            tap($restaurant)->update($data->onlyModelAttributes());
            $this->attachMedia($data, $restaurant, true);

            return $restaurant;
        });
    }

    private function attachMedia(RestaurantData $data, Restaurant $restaurant, bool $isUpdate): void
    {
        if ($data->primaryImage !== null) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->primaryImage, $restaurant, 'primary-image');
            } else {
                MediaHelper::uploadMedia($data->primaryImage, $restaurant, 'primary-image');
            }
        }

        if ($data->bannerImage !== null) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->bannerImage, $restaurant, 'banner-image');
            } else {
                MediaHelper::uploadMedia($data->bannerImage, $restaurant, 'banner-image');
            }
        }
    }
}
