<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Resources\SmStores\SmStoreResource;
use App\Filament\Support\AdminUiFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

final class SupermarketStatsPage extends Page
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    public string $search = '';

    public string $dateRange = '30';

    public string $revenueState = 'all';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.supermarket-admin.pages.supermarket-stats';

    public static function canAccess(): bool
    {
        return self::dashboardAllowed('supermarket_orders.view');
    }

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.stats.nav_title');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.stats.description');
    }

    public function getTitle(): string|Htmlable
    {
        return __('supermarket_admin.stats.title');
    }

    public function getViewData(): array
    {
        $search = mb_trim($this->search);
        $days = max(7, (int) $this->dateRange);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now();

        $dailyQuery = DB::table('sm_orders')
            ->join('sm_stores', 'sm_stores.id', '=', 'sm_orders.store_id')
            ->whereBetween('sm_orders.created_at', [$from, $to])
            ->where('sm_orders.status', 'completed')
            ->when($search !== '', fn ($query) => $query->where('sm_stores.name', 'like', '%'.$search.'%'))
            ->selectRaw('sm_orders.store_id, sm_stores.name as store_name, DATE(sm_orders.created_at) as date')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(sm_orders.total_amount), 0) as orders_revenue')
            ->selectRaw('COUNT(DISTINCT sm_orders.customer_id) as unique_customers')
            ->selectRaw(
                'COUNT(DISTINCT CASE
                    WHEN DATE(sm_orders.created_at) = DATE((
                        SELECT MIN(first_order.created_at)
                        FROM sm_orders first_order
                        WHERE first_order.store_id = sm_orders.store_id
                          AND first_order.customer_id = sm_orders.customer_id
                    ))
                    THEN sm_orders.customer_id
                END) as new_customers'
            )
            ->groupBy('sm_orders.store_id', 'sm_stores.name', DB::raw('DATE(sm_orders.created_at)'));

        if ($this->revenueState === 'with_revenue') {
            $dailyQuery->havingRaw('COALESCE(SUM(sm_orders.total_amount), 0) > 0');
        } elseif ($this->revenueState === 'without_revenue') {
            $dailyQuery->havingRaw('COALESCE(SUM(sm_orders.total_amount), 0) <= 0');
        }

        $allDailyStats = $dailyQuery
            ->orderByDesc('date')
            ->orderBy('store_name')
            ->get();

        $totalOrders = (int) $allDailyStats->sum(fn ($row): int => (int) $row->orders_count);
        $totalRevenue = (float) $allDailyStats->sum(fn ($row): float => (float) $row->orders_revenue);
        $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;
        $trackedStores = $allDailyStats->pluck('store_id')->unique()->count();
        $dailyStats = $allDailyStats->take(100)->values();

        return [
            'dailyStats' => $dailyStats,
            'summary' => [
                'totalOrders' => $totalOrders,
                'totalRevenue' => round($totalRevenue, 2),
                'averageOrderValue' => round($averageOrderValue, 2),
                'trackedStores' => $trackedStores,
                'totalRevenueFormatted' => AdminUiFormatter::formatCurrency(round($totalRevenue, 2)),
                'averageOrderValueFormatted' => AdminUiFormatter::formatCurrency(round($averageOrderValue, 2)),
            ],
            'filterOptions' => [
                'range' => [
                    '7' => __('supermarket_admin.filters.last_7_days'),
                    '30' => __('supermarket_admin.filters.last_30_days'),
                    '90' => __('supermarket_admin.filters.last_90_days'),
                ],
                'revenueState' => [
                    'all' => __('supermarket_admin.filters.all_rows'),
                    'with_revenue' => __('supermarket_admin.filters.with_revenue'),
                    'without_revenue' => __('supermarket_admin.filters.without_revenue'),
                ],
            ],
            'actionUrls' => [
                'stores' => SmStoreResource::getUrl('index'),
                'orders' => SmOrderResource::getUrl('index'),
                'hub' => SupermarketSectionHub::getUrl(),
            ],
        ];
    }
}
