<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Models\RestaurantFinancialSetting;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

final class RestaurantGeographicCoverage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $title = 'التغطية الجغرافية للمطاعم';

    protected static ?string $navigationLabel = 'التغطية الجغرافية';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected string $view = 'filament.cleaning-admin.pages.restaurant-geographic-coverage';

    public static function getNavigationTooltip(): ?string
    {
        return 'تحليل التغطية حسب الأحياء لاكتشاف فجوات العرض مقابل الطلب.';
    }

    public function getViewData(): array
    {
        $thresholds = RestaurantFinancialSetting::query()->first()?->coverage_thresholds ?? ['low' => 3, 'good' => 7];

        $rows = DB::table('restaurants')
            ->leftJoin('orders', function ($join): void {
                $join->on('orders.restaurant_id', '=', 'restaurants.id')
                    ->where('orders.created_at', '>=', now()->subDays(30));
            })
            ->selectRaw("COALESCE(restaurants.district, 'غير محدد') AS neighborhood")
            ->selectRaw('COUNT(DISTINCT CASE WHEN restaurants.is_active = 1 THEN restaurants.id END) AS active_restaurants')
            ->selectRaw('COUNT(orders.id) AS total_orders')
            ->groupBy('neighborhood')
            ->orderBy('neighborhood')
            ->get()
            ->map(function (object $row) use ($thresholds): array {
                $activeRestaurants = max((int) $row->active_restaurants, 1);
                $averageDailyDemand = (int) ceil(((int) $row->total_orders) / 30);
                $coverageRatio = (float) round($averageDailyDemand / $activeRestaurants, 2);

                $level = 'Good';
                if ($coverageRatio <= (float) ($thresholds['low'] ?? 3)) {
                    $level = 'Low';
                } elseif ($coverageRatio >= (float) ($thresholds['good'] ?? 7)) {
                    $level = 'High';
                }

                return [
                    'neighborhood' => (string) $row->neighborhood,
                    'active_restaurants' => (int) $row->active_restaurants,
                    'avg_daily_demand' => $averageDailyDemand,
                    'coverage_ratio' => $coverageRatio,
                    'coverage_level' => __('restaurant_admin.enums.coverage_level.'.$level),
                ];
            })
            ->values();

        return ['rows' => $rows];
    }
}
