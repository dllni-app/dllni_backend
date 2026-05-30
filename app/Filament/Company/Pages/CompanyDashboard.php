<?php

declare(strict_types=1);

namespace App\Filament\Company\Pages;

use App\Filament\Company\Widgets\DeliveryKpiStatsWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

final class CompanyDashboard extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -2;

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.dashboard');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.dashboard.title');
    }

    public function getWidgets(): array
    {
        return [
            DeliveryKpiStatsWidget::class,
        ];
    }
}
