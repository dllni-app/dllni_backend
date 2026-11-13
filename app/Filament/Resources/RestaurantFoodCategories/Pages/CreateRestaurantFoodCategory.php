<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantFoodCategories\Pages;

use App\Filament\Resources\RestaurantFoodCategories\RestaurantFoodCategoryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRestaurantFoodCategory extends CreateRecord
{
    protected static string $resource = RestaurantFoodCategoryResource::class;
}
