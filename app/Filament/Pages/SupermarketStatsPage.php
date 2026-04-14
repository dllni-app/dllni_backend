<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Resources\SmStores\SmStoreResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class SupermarketStatsPage extends Page
{
    use ResolvesSupermarketNavigationGroup;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.supermarket-admin.pages.supermarket-stats';

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
        $dailyStats = SmStoreDailyStat::query()
            ->with('store:id,name')
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
            ],
            'actionUrls' => [
                'stores' => SmStoreResource::getUrl('index'),
                'orders' => SmOrderResource::getUrl('index'),
            ],
        ];
    }
}
