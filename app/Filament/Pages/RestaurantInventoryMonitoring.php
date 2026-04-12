<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
use App\Filament\Support\RestaurantAdminUrls;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Resturants\Models\InventoryLog;
use Modules\Resturants\Models\Product;
use UnitEnum;

final class RestaurantInventoryMonitoring extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected static ?string $navigationLabel = 'مراقبة المخزون والتنبيهات';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.cleaning-admin.pages.restaurant-inventory-monitoring';

    public static function getNavigationTooltip(): ?string
    {
        return __('restaurant_admin.inventory.description');
    }

    public function getTitle(): string|Htmlable
    {
        return __('restaurant_admin.inventory.title');
    }

    public function getViewData(): array
    {
        $lowStockProducts = Product::query()
            ->lowStock()
            ->with('restaurant:id,name')
            ->orderBy('stock_quantity')
            ->limit(100)
            ->get();

        $recentInventoryLogs = InventoryLog::query()
            ->with(['product' => fn ($q) => $q->with('restaurant:id,name')])
            ->latest()
            ->limit(50)
            ->get();

        return [
            'lowStockProducts' => $lowStockProducts,
            'recentInventoryLogs' => $recentInventoryLogs,
            'actionUrls' => [
                'restaurants' => RestaurantResource::getUrl('index'),
                'orders' => OrderResource::getUrl('index'),
                'disputes' => RestaurantAdminUrls::disputesOpenOrReview(),
            ],
        ];
    }
}
