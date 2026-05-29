<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Support\AdminUiFormatter;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Resources\SmStores\SmStoreResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class SupermarketStatsPage extends Page
{
    use ResolvesSupermarketNavigationGroup;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.supermarket-admin.pages.supermarket-stats';

    public string $search = '';

    public string $dateRange = '30';

    public string $revenueState = 'all';

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
        $search = trim($this->search);
        $days = max(7, (int) $this->dateRange);
        $fromDate = now()->subDays($days - 1)->toDateString();

        $dailyStats = SmStoreDailyStat::query()
            ->with('store:id,name')
            ->whereDate('date', '>=', $fromDate)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('store', function (Builder $storeQuery) use ($search): void {
                    $storeQuery->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($this->revenueState !== 'all', function (Builder $query): void {
                if ($this->revenueState === 'with_revenue') {
                    $query->where('orders_revenue', '>', 0);

                    return;
                }

                $query->where(function (Builder $inner): void {
                    $inner
                        ->whereNull('orders_revenue')
                        ->orWhere('orders_revenue', '<=', 0);
                });
            })
            ->orderByDesc('date')
            ->limit(100)
            ->get();

        $totalOrders = (int) $dailyStats->sum('orders_count');
        $totalRevenue = (float) $dailyStats->sum(fn (SmStoreDailyStat $row): float => (float) ($row->orders_revenue ?? 0));
        $averageOrderValue = $totalOrders > 0
            ? $totalRevenue / $totalOrders
            : 0.0;
        $trackedStores = (int) $dailyStats
            ->pluck('store_id')
            ->filter()
            ->unique()
            ->count();

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
