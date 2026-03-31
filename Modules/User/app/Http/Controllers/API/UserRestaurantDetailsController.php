<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\Resturants\Models\Restaurant;

final class UserRestaurantDetailsController
{
    public function __invoke(Restaurant $restaurant): RestaurantResource
    {
        return RestaurantResource::make($restaurant->load([
            'media',
            'user',
            'operatingHours',
            'cuisineTypes',
            'offers',
        ]));
    }
}
