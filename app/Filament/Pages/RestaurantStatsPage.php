<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Resturants\Models\RestaurantDailyStat;
use Modules\Resturants\Models\RestaurantMonthlyStat;
use UnitEnum;

final class RestaurantStatsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected static ?string $navigationLabel = 'الإحصائيات اليومية والشهرية';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.cleaning-admin.pages.restaurant-stats';

    public static function getNavigationTooltip(): ?string
    {
        return __('restaurant_admin.stats.description');
    }

    public function getTitle(): string|Htmlable
    {
        return __('restaurant_admin.stats.title');
    }

    public function getViewData(): array
    {
        $dailyStats = RestaurantDailyStat::query()
            ->with('restaurant:id,name')
            ->orderByDesc('stat_date')
            ->limit(100)
            ->get();

        $monthlyStats = RestaurantMonthlyStat::query()
            ->with('restaurant:id,name')
            ->orderByDesc('stat_year')
            ->orderByDesc('stat_month')
            ->limit(100)
            ->get();

        return [
            'dailyStats' => $dailyStats,
            'monthlyStats' => $monthlyStats,
        ];
    }
}
