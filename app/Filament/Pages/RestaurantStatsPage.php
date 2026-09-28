<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
use App\Filament\Support\AdminUiFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use UnitEnum;

final class RestaurantStatsPage extends Page
{
    use AuthorizesPlatformAdminResource;

    public string $search = '';

    public string $dateRange = '30';

    public string $revenueState = 'all';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.cleaning-admin.pages.restaurant-stats';

    public static function canAccess(): bool
    {
        return self::dashboardAllowed('restaurant_orders.view');
    }

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
        $search = mb_trim($this->search);
        $days = max(7, (int) $this->dateRange);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now();

        $dailyQuery = DB::table('orders')
            ->join('restaurants', 'restaurants.id', '=', 'orders.restaurant_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', 'completed')
            ->when($search !== '', fn ($query) => $query->where('restaurants.name', 'like', '%'.$search.'%'))
            ->selectRaw('orders.restaurant_id, restaurants.name as restaurant_name, DATE(orders.created_at) as stat_date')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as revenue')
            ->selectRaw('COALESCE(AVG(orders.total_amount), 0) as average_order_value')
            ->groupBy('orders.restaurant_id', 'restaurants.name', DB::raw('DATE(orders.created_at)'));

        $this->applyRevenueFilter($dailyQuery, 'SUM(orders.total_amount)');

        $allDailyStats = $dailyQuery
            ->orderByDesc('stat_date')
            ->orderBy('restaurant_name')
            ->get();
        $dailyStats = $allDailyStats
            ->take(100)
            ->map(fn ($row): Fluent => new Fluent([
                'restaurant_id' => (int) $row->restaurant_id,
                'restaurant' => new Fluent(['name' => $row->restaurant_name]),
                'stat_date' => Carbon::parse($row->stat_date),
                'orders_count' => (int) $row->orders_count,
                'revenue' => (float) $row->revenue,
                'average_order_value' => (float) $row->average_order_value,
            ]))
            ->values();

        $monthlyStats = $allDailyStats
            ->groupBy(fn ($row): string => $row->restaurant_id.'|'.mb_substr((string) $row->stat_date, 0, 7))
            ->map(function ($rows): Fluent {
                $first = $rows->first();
                [$year, $month] = array_map('intval', explode('-', mb_substr((string) $first->stat_date, 0, 7)));
                $ordersCount = (int) $rows->sum(fn ($row): int => (int) $row->orders_count);
                $revenue = (float) $rows->sum(fn ($row): float => (float) $row->revenue);

                return new Fluent([
                    'restaurant_id' => (int) $first->restaurant_id,
                    'restaurant' => new Fluent(['name' => $first->restaurant_name]),
                    'stat_year' => $year,
                    'stat_month' => $month,
                    'orders_count' => $ordersCount,
                    'revenue' => $revenue,
                    'average_order_value' => $ordersCount > 0 ? $revenue / $ordersCount : 0.0,
                ]);
            })
            ->sortByDesc(fn (Fluent $row): string => sprintf('%04d-%02d', $row->stat_year, $row->stat_month))
            ->take(100)
            ->values();
        $totalOrders = (int) $allDailyStats->sum(fn ($row): int => (int) $row->orders_count);
        $totalRevenue = (float) $allDailyStats->sum(fn ($row): float => (float) $row->revenue);
        $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;
        $trackedRestaurants = $allDailyStats->pluck('restaurant_id')->unique()->count();

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

    private function applyRevenueFilter($query, string $sumExpression): void
    {
        if ($this->revenueState === 'with_revenue') {
            $query->havingRaw('COALESCE('.$sumExpression.', 0) > 0');

            return;
        }
        if ($this->revenueState === 'without_revenue') {
            $query->havingRaw('COALESCE('.$sumExpression.', 0) <= 0');
        }
    }
}
