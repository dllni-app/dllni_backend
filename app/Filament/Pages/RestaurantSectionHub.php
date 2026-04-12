<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantOrderDispute;
use UnitEnum;

final class RestaurantSectionHub extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'قسم المطاعم';

    protected static ?string $title = 'قسم المطاعم';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    protected string $view = 'filament.cleaning-admin.pages.restaurant-section-hub';

    public function getViewData(): array
    {
        $now = now();

        $kpis = [
            [
                'label' => __('restaurant_admin.hub.kpis.active_restaurants'),
                'value' => Restaurant::query()->where('is_active', true)->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.temporarily_closed_restaurants'),
                'value' => Restaurant::query()->where('is_temporarily_closed', true)->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.open_disputes'),
                'value' => RestaurantOrderDispute::query()->whereIn('status', ['open', 'under_review'])->count(),
                'url' => RestaurantOrderDisputeResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.low_stock_products'),
                'value' => Product::query()->lowStock()->count(),
                'url' => RestaurantInventoryMonitoring::getUrl(),
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.active_offers'),
                'value' => Offer::query()
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                    ->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.pending_orders'),
                'value' => Order::query()->where('status', OrderStatus::Pending->value)->count(),
                'url' => OrderResource::getUrl('index'),
            ],
        ];

        $priorityRestaurants = Restaurant::query()
            ->select(['id', 'name', 'reputation_score', 'warning_count', 'is_temporarily_closed', 'suspension_until'])
            ->where(function ($query) use ($now): void {
                $query
                    ->where('is_temporarily_closed', true)
                    ->orWhere('warning_count', '>', 0)
                    ->orWhere('reputation_score', '<', 70)
                    ->orWhere(function ($nested) use ($now): void {
                        $nested
                            ->whereNotNull('suspension_until')
                            ->where('suspension_until', '>', $now);
                    });
            })
            ->orderByDesc('warning_count')
            ->orderBy('reputation_score')
            ->limit(6)
            ->get();

        $openDisputes = RestaurantOrderDispute::query()
            ->with(['order.restaurant:id,name'])
            ->whereIn('status', ['open', 'under_review'])
            ->latest('created_at')
            ->limit(6)
            ->get();

        $criticalProducts = Product::query()
            ->lowStock()
            ->with('restaurant:id,name')
            ->orderBy('stock_quantity')
            ->limit(6)
            ->get();

        $checklist = [
            [
                'label' => __('restaurant_admin.hub.checklist.missing_operating_hours'),
                'value' => Restaurant::query()->whereDoesntHave('operatingHours', function ($query): void {
                    $query
                        ->where('is_closed', false)
                        ->whereNotNull('open_time')
                        ->whereNotNull('close_time');
                })->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_cuisine_types'),
                'value' => Restaurant::query()->whereDoesntHave('cuisineTypes')->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_active_products'),
                'value' => Restaurant::query()->whereDoesntHave('products', function ($query): void {
                    $query->where('is_available', true);
                })->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_active_offers'),
                'value' => Restaurant::query()->whereDoesntHave('offers', function ($query) use ($now): void {
                    $query
                        ->where('is_active', true)
                        ->where(fn ($nested) => $nested->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                        ->where(fn ($nested) => $nested->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
                })->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_active_coupons'),
                'value' => Restaurant::query()->whereDoesntHave('promoCodes', function ($query) use ($now): void {
                    $query
                        ->where('is_active', true)
                        ->where(fn ($nested) => $nested->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                        ->where(fn ($nested) => $nested->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                        ->where(fn ($nested) => $nested->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'));
                })->count(),
                'url' => RestaurantResource::getUrl('index'),
            ],
        ];

        return [
            'kpis' => $kpis,
            'quickActions' => [
                ['label' => __('restaurant_admin.hub.quick_actions.restaurants'), 'url' => RestaurantResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.quick_actions.orders'), 'url' => OrderResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.quick_actions.disputes'), 'url' => RestaurantOrderDisputeResource::getUrl('index')],
                ['label' => __('restaurant_admin.hub.quick_actions.inventory'), 'url' => RestaurantInventoryMonitoring::getUrl()],
                ['label' => __('restaurant_admin.hub.quick_actions.stats'), 'url' => RestaurantStatsPage::getUrl()],
            ],
            'priorityRestaurants' => $priorityRestaurants,
            'openDisputes' => $openDisputes,
            'criticalProducts' => $criticalProducts,
            'checklist' => $checklist,
        ];
    }
}
