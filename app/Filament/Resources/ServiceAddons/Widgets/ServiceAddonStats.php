<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Widgets;

use App\Models\ServiceAddon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Enums\AddonPricingType;

final class ServiceAddonStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [
            Stat::make(__('cleaning_admin.service_addons.stats.total'), ServiceAddon::query()->count())
                ->icon('heroicon-o-squares-plus')
                ->color('primary'),
            Stat::make(__('cleaning_admin.service_addons.stats.active'), ServiceAddon::query()->where('is_active', true)->count())
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make(__('cleaning_admin.service_addons.stats.fixed'), ServiceAddon::query()->where('pricing_type', AddonPricingType::Fixed->value)->count())
                ->icon('heroicon-o-banknotes')
                ->color('info'),
            Stat::make(__('cleaning_admin.service_addons.stats.percentage'), ServiceAddon::query()->where('pricing_type', AddonPricingType::Percentage->value)->count())
                ->icon('heroicon-o-chart-pie')
                ->color('warning'),
        ];
    }
}
