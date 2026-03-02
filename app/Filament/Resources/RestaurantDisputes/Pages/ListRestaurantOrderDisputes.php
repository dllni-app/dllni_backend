<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes\Pages;

use App\Filament\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantOrderDisputes extends ListRecords
{
    protected static string $resource = RestaurantOrderDisputeResource::class;

    public function getSubheading(): ?string
    {
        return __('restaurant_admin.pages.disputes.list');
    }
}
