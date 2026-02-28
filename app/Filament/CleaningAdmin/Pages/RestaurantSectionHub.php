<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\RestaurantAutomationRuleResource;
use App\Filament\CleaningAdmin\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use App\Filament\CleaningAdmin\Resources\Restaurants\RestaurantResource;
use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\RestaurantSystemAlertResource;
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
                ['label' => __('restaurant_admin.hub.team'), 'url' => RestaurantTeamManagement::getUrl()],
                ['label' => __('restaurant_admin.hub.reputation'), 'url' => RestaurantResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.alerts'), 'url' => RestaurantSystemAlertResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.financial'), 'url' => RestaurantFinancialSettings::getUrl()],
                ['label' => __('restaurant_admin.hub.disputes'), 'url' => RestaurantOrderDisputeResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.coverage'), 'url' => RestaurantGeographicCoverage::getUrl()],
                ['label' => __('restaurant_admin.hub.time_monitoring'), 'url' => RestaurantTimeEndMonitoring::getUrl()],
                ['label' => __('restaurant_admin.hub.automation'), 'url' => RestaurantAutomationRuleResource::getUrl('index')],
            ],
        ];
    }
}
