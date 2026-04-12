<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
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

    protected static ?int $navigationSort = 6;

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

        $totalOrders = (int) $dailyStats->sum('orders_count');
        $totalRevenue = (float) $dailyStats->sum(fn ($row) => (float) ($row->revenue ?? 0));
        $averageOrderValue = (float) $dailyStats->avg(fn ($row) => (float) ($row->average_order_value ?? 0));
        $trackedRestaurants = (int) $dailyStats
            ->pluck('restaurant_id')
            ->filter()
            ->unique()
            ->count();

        return [
            'dailyStats' => $dailyStats,
            'monthlyStats' => $monthlyStats,
            'summary' => [
                'totalOrders' => $totalOrders,
                'totalRevenue' => round($totalRevenue, 2),
                'averageOrderValue' => round($averageOrderValue, 2),
                'trackedRestaurants' => $trackedRestaurants,
            ],
            'actionUrls' => [
                'restaurants' => RestaurantResource::getUrl('index'),
                'orders' => OrderResource::getUrl('index'),
            ],
        ];
    }
}
