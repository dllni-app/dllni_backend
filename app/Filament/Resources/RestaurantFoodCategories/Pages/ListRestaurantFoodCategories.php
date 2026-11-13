<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantFoodCategories\Pages;

use App\Filament\Resources\RestaurantFoodCategories\RestaurantFoodCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantFoodCategories extends ListRecords
{
    protected static string $resource = RestaurantFoodCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
