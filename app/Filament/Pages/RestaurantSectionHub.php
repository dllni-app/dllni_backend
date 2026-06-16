<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\RestaurantAdminReadinessFilter;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
use App\Filament\Support\RestaurantAdminUrls;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantOrderDispute;
use UnitEnum;

final class RestaurantSectionHub extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    protected string $view = 'filament.cleaning-admin.pages.restaurant-section-hub';

    public string $search = '';

    public string $focus = 'all';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('restaurant_admin.section');
    }

    public static function getNavigationLabel(): string
    {
        return __('restaurant_admin.hub.nav_title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('restaurant_admin.hub.page_title');
    }

    public function getViewData(): array
    {
        $now = now();

        $kpis = [
            [
                'label' => __('restaurant_admin.hub.kpis.active_restaurants'),
                'value' => Restaurant::query()->where('is_active', true)->count(),
                'url' => RestaurantResource::getUrl('index'),
                'tone' => 'success',
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.temporarily_closed_restaurants'),
                'value' => Restaurant::query()->where('is_temporarily_closed', true)->count(),
                'url' => RestaurantAdminUrls::restaurantsTemporarilyClosed(),
                'tone' => 'warning',
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.open_disputes'),
                'value' => RestaurantOrderDispute::query()->whereIn('status', [
                    RestaurantDisputeStatus::Open->value,
                    RestaurantDisputeStatus::UnderReview->value,
                ])->count(),
                'url' => RestaurantAdminUrls::disputesOpenOrReview(),
                'tone' => 'danger',
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.low_stock_products'),
                'value' => Product::query()->lowStock()->count(),
                'url' => self::getUrl([], isAbsolute: false) . '#restaurant-hub-low-stock',
                'tone' => 'warning',
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.active_offers'),
                'value' => Offer::query()
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                    ->count(),
                'url' => RestaurantAdminUrls::restaurantsIndex(RestaurantAdminReadinessFilter::MissingActiveOffers),
                'hint' => __('restaurant_admin.hub.kpis.active_offers_hint'),
                'tone' => 'info',
            ],
            [
                'label' => __('restaurant_admin.hub.kpis.pending_orders'),
                'value' => Order::query()->where('status', OrderStatus::Pending->value)->count(),
                'url' => RestaurantAdminUrls::ordersPending(),
                'tone' => 'primary',
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
            ->whereIn('status', [
                RestaurantDisputeStatus::Open->value,
                RestaurantDisputeStatus::UnderReview->value,
            ])
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
                'value' => Restaurant::query()->adminMissingOperatingHours()->count(),
                'url' => RestaurantAdminUrls::restaurantsIndex(RestaurantAdminReadinessFilter::MissingOperatingHours),
                'tone' => 'warning',
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_cuisine_types'),
                'value' => Restaurant::query()->adminMissingCuisineTypes()->count(),
                'url' => RestaurantAdminUrls::restaurantsIndex(RestaurantAdminReadinessFilter::MissingCuisineTypes),
                'tone' => 'warning',
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_active_products'),
                'value' => Restaurant::query()->adminMissingAvailableProducts()->count(),
                'url' => RestaurantAdminUrls::restaurantsIndex(RestaurantAdminReadinessFilter::MissingAvailableProducts),
                'tone' => 'danger',
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_active_offers'),
                'value' => Restaurant::query()->adminMissingActiveOffers()->count(),
                'url' => RestaurantAdminUrls::restaurantsIndex(RestaurantAdminReadinessFilter::MissingActiveOffers),
                'tone' => 'info',
            ],
            [
                'label' => __('restaurant_admin.hub.checklist.missing_active_coupons'),
                'value' => Restaurant::query()->adminMissingActiveCoupons()->count(),
                'url' => RestaurantAdminUrls::restaurantsIndex(RestaurantAdminReadinessFilter::MissingActiveCoupons),
                'tone' => 'info',
            ],
        ];

        $priorityRestaurantRows = $priorityRestaurants->map(fn (Restaurant $restaurant): array => [
            'label' => $restaurant->name,
            'meta' => __('restaurant_admin.hub.reputation_score') . ': ' . (int) ($restaurant->reputation_score ?? 0)
                . ' • ' . __('restaurant_admin.hub.warning_count') . ': ' . (int) ($restaurant->warning_count ?? 0),
            'url' => RestaurantResource::getUrl('view', ['record' => $restaurant]),
            'tone' => ($restaurant->warning_count ?? 0) > 0 ? 'warning' : 'neutral',
            'badge' => $restaurant->is_temporarily_closed ? __('restaurant_admin.filters.temporarily_closed') : null,
        ])->all();

        $priorityDisputeRows = $openDisputes->map(fn (RestaurantOrderDispute $dispute): array => [
            'label' => $dispute->ticket_number,
            'meta' => ($dispute->order?->restaurant?->name ?? '—')
                . ' • ' . __('restaurant_admin.hub.status') . ': ' . $this->translatedDisputeStatus($dispute),
            'url' => RestaurantOrderDisputeResource::getUrl('view', ['record' => $dispute]),
            'tone' => 'danger',
            'badge' => __('restaurant_admin.filters.requires_action'),
        ])->all();

        $priorityProductRows = $criticalProducts->map(fn (Product $product): array => [
            'label' => $product->name,
            'meta' => ($product->restaurant?->name ?? '—')
                . ' • ' . __('restaurant_admin.inventory.stock_quantity') . ': ' . (int) ($product->stock_quantity ?? 0)
                . ' • ' . __('restaurant_admin.inventory.low_stock_threshold') . ': ' . (int) ($product->low_stock_threshold ?? 0),
            'url' => RestaurantResource::getUrl('view', ['record' => $product->restaurant_id]),
            'tone' => 'warning',
            'badge' => __('restaurant_admin.filters.low_stock'),
        ])->all();

        $priorityRestaurantRows = $this->filterRows($priorityRestaurantRows);
        $priorityDisputeRows = $this->filterRows($priorityDisputeRows);
        $priorityProductRows = $this->filterRows($priorityProductRows);

        if ($this->focus !== 'all') {
            if ($this->focus !== 'restaurants') {
                $priorityRestaurantRows = [];
            }

            if ($this->focus !== 'disputes') {
                $priorityDisputeRows = [];
            }

            if ($this->focus !== 'inventory') {
                $priorityProductRows = [];
            }
        }

        return [
            'kpis' => $kpis,
            'filterOptions' => [
                'focus' => [
                    'all' => __('restaurant_admin.filters.focus_all'),
                    'restaurants' => __('restaurant_admin.filters.focus_restaurants'),
                    'disputes' => __('restaurant_admin.filters.focus_disputes'),
                    'inventory' => __('restaurant_admin.filters.focus_inventory'),
                ],
            ],
            'quickActions' => [
                ['label' => __('restaurant_admin.hub.quick_actions.restaurants'), 'url' => RestaurantResource::getUrl('index'), 'tone' => 'success'],
                ['label' => __('restaurant_admin.hub.quick_actions.orders'), 'url' => OrderResource::getUrl('index'), 'tone' => 'primary'],
                ['label' => __('restaurant_admin.hub.quick_actions.disputes'), 'url' => RestaurantOrderDisputeResource::getUrl('index'), 'tone' => 'danger'],
                ['label' => __('restaurant_admin.hub.quick_actions.stats'), 'url' => RestaurantStatsPage::getUrl(), 'tone' => 'info'],
            ],
            'priorityRestaurantRows' => $priorityRestaurantRows,
            'priorityDisputeRows' => $priorityDisputeRows,
            'priorityProductRows' => $priorityProductRows,
            'checklist' => $checklist,
        ];
    }

    private function translatedDisputeStatus(RestaurantOrderDispute $dispute): string
    {
        $status = $dispute->status;
        $value = $status instanceof RestaurantDisputeStatus
            ? $status->value
            : (is_string($status) ? $status : RestaurantDisputeStatus::Open->value);

        return __('restaurant_admin.enums.dispute_status.' . $value);
    }

    /**
     * @param  list<array{label:string,meta?:string,url:string,tone?:string,badge?:string|null}>  $rows
     * @return list<array{label:string,meta?:string,url:string,tone?:string,badge?:string|null}>
     */
    private function filterRows(array $rows): array
    {
        $search = Str::lower(trim($this->search));

        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($search): bool {
            $haystack = Str::lower(($row['label'] ?? '') . ' ' . ($row['meta'] ?? ''));

            return Str::contains($haystack, $search);
        }));
    }
}
