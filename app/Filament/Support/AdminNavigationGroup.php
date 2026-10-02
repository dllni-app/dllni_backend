<?php

declare(strict_types=1);

namespace App\Filament\Support;

final class AdminNavigationGroup
{
    public static function delivery(): string
    {
        return __('admin_navigation.groups.delivery');
    }

    public static function restaurants(): string
    {
        return __('admin_navigation.groups.restaurants');
    }

    public static function supermarkets(): string
    {
        return __('admin_navigation.groups.supermarkets');
    }

    public static function cleaning(): string
    {
        return __('admin_navigation.groups.cleaning');
    }

    public static function general(): string
    {
        return __('admin_navigation.groups.general');
    }
}
