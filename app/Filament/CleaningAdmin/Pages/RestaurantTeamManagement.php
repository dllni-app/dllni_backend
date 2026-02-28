<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Filament\CleaningAdmin\Resources\Roles\RoleResource;
use App\Filament\CleaningAdmin\Resources\Users\UserResource;
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

    public function getTitle(): string
    {
        return __('restaurant_admin.team.title');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('restaurant_admin.team.description');
    }

    public function getViewData(): array
    {
        return [
            'cards' => [
                ['label' => __('restaurant_admin.team.admin_users'), 'url' => UserResource::getUrl('index')],
                ['label' => __('restaurant_admin.team.roles'), 'url' => RoleResource::getUrl('index')],
            ],
        ];
    }
}
