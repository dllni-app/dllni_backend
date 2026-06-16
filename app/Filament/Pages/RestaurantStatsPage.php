<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\AdminUiFormatter;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\RestaurantDailyStat;
use Modules\Resturants\Models\RestaurantMonthlyStat;
use UnitEnum;

final class RestaurantStatsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.cleaning-admin.pages.restaurant-stats';

    public string $search = '';

    public string $dateRange = '30';

    public string $revenueState = 'all';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('restaurant_admin.section');
    }

    public static function getNavigationLabel(): string
    {
        return __('restaurant_admin.stats.nav_title');
    }

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
        $search = trim($this->search);
        $days = max(7, (int) $this->dateRange);
        $fromDate = now()->subDays($days - 1)->toDateString();

        $dailyStats = RestaurantDailyStat::query()
            ->with('restaurant:id,name')
            ->whereDate('stat_date', '>=', $fromDate)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('restaurant', function (Builder $restaurantQuery) use ($search): void {
                    $restaurantQuery->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($this->revenueState !== 'all', function (Builder $query): void {
                if ($this->revenueState === 'with_revenue') {
                    $query->where('revenue', '>', 0);

                    return;
                }

                $query->where(function (Builder $inner): void {
                    $inner
                        ->whereNull('revenue')
                        ->orWhere('revenue', '<=', 0);
                });
            })
            ->orderByDesc('stat_date')
            ->limit(100)
            ->get();

        $monthlyStats = RestaurantMonthlyStat::query()
            ->with('restaurant:id,name')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('restaurant', function (Builder $restaurantQuery) use ($search): void {
                    $restaurantQuery->where('name', 'like', '%' . $search . '%');
                });
            })
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
                'totalRevenueFormatted' => AdminUiFormatter::formatCurrency(round($totalRevenue, 2)),
                'averageOrderValueFormatted' => AdminUiFormatter::formatCurrency(round($averageOrderValue, 2)),
            ],
            'filterOptions' => [
                'range' => [
                    '7' => __('restaurant_admin.filters.last_7_days'),
                    '30' => __('restaurant_admin.filters.last_30_days'),
                    '90' => __('restaurant_admin.filters.last_90_days'),
                ],
                'revenueState' => [
                    'all' => __('restaurant_admin.filters.all_rows'),
                    'with_revenue' => __('restaurant_admin.filters.with_revenue'),
                    'without_revenue' => __('restaurant_admin.filters.without_revenue'),
                ],
            ],
            'actionUrls' => [
                'restaurants' => RestaurantResource::getUrl('index'),
                'orders' => OrderResource::getUrl('index'),
                'hub' => RestaurantSectionHub::getUrl(),
            ],
        ];
    }
}
