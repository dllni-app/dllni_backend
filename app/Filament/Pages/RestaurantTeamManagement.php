<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Restaurants\RestaurantResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class RestaurantTeamManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected string $view = 'filament.cleaning-admin.pages.restaurant-team-management';

    public static function getNavigationLabel(): string
    {
        return __('restaurant_admin.team.title');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('restaurant_admin.team.description');
    }

    public function getTitle(): string
    {
        return __('restaurant_admin.team.title');
    }

    public function getViewData(): array
    {
        return [
            'cards' => [
                ['label' => __('restaurant_admin.hub.roles_and_staff'), 'url' => RestaurantResource::getUrl('index')],
            ],
        ];
    }
}
