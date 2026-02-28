<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class RestaurantSectionHub extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'قسم المطاعم';

    protected static ?string $title = 'قسم المطاعم';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.cleaning-admin.pages.restaurant-section-hub';

    public function getViewData(): array
    {
        return [
            'cards' => [
                ['label' => __('restaurant_admin.hub.disputes'), 'url' => RestaurantOrderDisputeResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.restaurants'), 'url' => RestaurantResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.orders'), 'url' => OrderResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.team'), 'url' => RestaurantTeamManagement::getUrl()],
                ['label' => __('restaurant_admin.hub.inventory'), 'url' => RestaurantInventoryMonitoring::getUrl()],
                ['label' => __('restaurant_admin.hub.stats'), 'url' => RestaurantStatsPage::getUrl()],
            ],
        ];
    }
}
