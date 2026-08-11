<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use App\Models\MasterProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class StoreOwnerDashboardPerformanceService
{
    public function performance(SmStore $store, string $range, ?string $from, ?string $to): array
    {
        [$start, $end] = $this->resolveRange($range, $from, $to);

        $ordersQuery = SmOrder::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end]);

        $totalOrders = (clone $ordersQuery)->count();

        $topProductRows = SmOrderItem::query()
            ->selectRaw('sm_products.id as product_id, sm_products.name as product_name, SUM(sm_order_items.quantity) as quantity_sold, SUM(sm_order_items.total_price) as revenue')
            ->join('sm_orders', 'sm_orders.id', '=', 'sm_order_items.order_id')
            ->join('sm_products', 'sm_products.id', '=', 'sm_order_items.product_id')
            ->where('sm_orders.store_id', $store->id)
            ->whereBetween('sm_orders.created_at', [$start, $end])
            ->where('sm_orders.status', '!=', SmOrderStatus::Cancelled->value)
            ->groupBy('sm_products.id', 'sm_products.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $productsById = SmProduct::query()
            ->with(['media', 'masterProduct.media'])
            ->whereIn('id', $topProductRows->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $topProducts = $topProductRows
            ->map(static function ($row) use ($productsById): array {
                /** @var SmProduct|null $product */
                $product = $productsById->get((int) $row->product_id);
                $media = $product?->getMedia(SmProduct::IMAGE_COLLECTION) ?? collect();

                if ($media->isEmpty() && $product?->masterProduct !== null) {
                    $media = $product->masterProduct->getMedia(MasterProduct::IMAGE_COLLECTION);

                    if ($media->isEmpty()) {
                        $fallbackMedia = $product->masterProduct->getFirstMedia();

                        if ($fallbackMedia !== null) {
                            $media = collect([$fallbackMedia]);
                        }
                    }
                }

                $imageUrls = $media
                    ->map(static fn ($mediaItem): string => $mediaItem->getFullUrl())
                    ->values();
                $primaryImage = $imageUrls->first();

                return [
                    'productId' => (int) $row->product_id,
                    'name' => (string) $row->product_name,
                    'quantity' => (int) $row->quantity_sold,
                    'revenue' => (float) $row->revenue,
                    'imageUrl' => $primaryImage,
                    'primaryImage' => $primaryImage,
                    'imageUrls' => $imageUrls->all(),
                ];
            })
            ->values()
            ->all();

        $ordersUsedOffersQuery = (clone $ordersQuery)
            ->where('status', '!=', SmOrderStatus::Cancelled->value)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('sm_order_items')
                    ->join('sm_offer_products', 'sm_offer_products.product_id', '=', 'sm_order_items.product_id')
                    ->join('sm_offers', function ($join): void {
                        $join->on('sm_offers.id', '=', 'sm_offer_products.offer_id')
                            ->on('sm_offers.store_id', '=', 'sm_orders.store_id');
                    })
                    ->whereColumn('sm_order_items.order_id', 'sm_orders.id')
                    ->where(function ($validity): void {
                        $validity->whereNull('sm_offers.starts_at')
                            ->orWhereColumn('sm_offers.starts_at', '<=', 'sm_orders.created_at');
                    })
                    ->where(function ($validity): void {
                        $validity->whereNull('sm_offers.ends_at')
                            ->orWhereColumn('sm_offers.ends_at', '>=', 'sm_orders.created_at');
                    });
            });

        $ordersUsedOffers = (clone $ordersUsedOffersQuery)->count();
        $offersRevenue = (float) (clone $ordersUsedOffersQuery)->sum('total_amount');
        $totalSavings = (float) (clone $ordersUsedOffersQuery)->sum('discount_amount');
        $utilizationRate = $totalOrders > 0 ? round(($ordersUsedOffers / $totalOrders) * 100, 2) : 0.0;

        $offerOrderPerformance = DB::table('sm_orders')
            ->join('sm_order_items', 'sm_order_items.order_id', '=', 'sm_orders.id')
            ->join('sm_offer_products', 'sm_offer_products.product_id', '=', 'sm_order_items.product_id')
            ->join('sm_offers', function ($join): void {
                $join->on('sm_offers.id', '=', 'sm_offer_products.offer_id')
                    ->on('sm_offers.store_id', '=', 'sm_orders.store_id');
            })
            ->where('sm_orders.store_id', $store->id)
            ->whereBetween('sm_orders.created_at', [$start, $end])
            ->where('sm_orders.status', '!=', SmOrderStatus::Cancelled->value)
            ->where(function ($query): void {
                $query->whereNull('sm_offers.starts_at')
                    ->orWhereColumn('sm_offers.starts_at', '<=', 'sm_orders.created_at');
            })
            ->where(function ($query): void {
                $query->whereNull('sm_offers.ends_at')
                    ->orWhereColumn('sm_offers.ends_at', '>=', 'sm_orders.created_at');
            })
            ->selectRaw(
                'sm_offers.id as offer_id,
                sm_offers.name as offer_name,
                sm_offers.offer_type,
                sm_offers.discount_value,
                sm_offers.discount_percent,
                sm_orders.id as order_id,
                sm_orders.total_amount as order_total,
                sm_orders.discount_amount as order_discount'
            )
            ->groupBy(
                'sm_offers.id',
                'sm_offers.name',
                'sm_offers.offer_type',
                'sm_offers.discount_value',
                'sm_offers.discount_percent',
                'sm_orders.id',
                'sm_orders.total_amount',
                'sm_orders.discount_amount'
            );

        $bestOffer = DB::query()
            ->fromSub($offerOrderPerformance, 'offer_orders')
            ->selectRaw(
                'offer_id,
                offer_name,
                offer_type,
                discount_value,
                discount_percent,
                COUNT(DISTINCT order_id) as uses_count,
                COALESCE(SUM(order_total), 0) as revenue,
                COALESCE(SUM(order_discount), 0) as total_savings'
            )
            ->groupBy('offer_id', 'offer_name', 'offer_type', 'discount_value', 'discount_percent')
            ->orderByDesc('revenue')
            ->first();

        return [
            'range' => [
                'key' => $range,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'topProducts' => $topProducts,
            'offersImpact' => [
                // Primary keys expected by the owner performance cards.
                'ordersUsedOffers' => $ordersUsedOffers,
                'utilizationRatePercent' => $utilizationRate,
                'offersRevenue' => $offersRevenue,
                // Backward-compatible aliases for older clients.
                'discountedOrdersCount' => $ordersUsedOffers,
                'conversionRatePercent' => $utilizationRate,
                'discountedRevenue' => $offersRevenue,
                'totalSavings' => $totalSavings,
            ],
            'bestOfferPerformance' => $bestOffer ? [
                'offerId' => (int) $bestOffer->offer_id,
                'name' => (string) $bestOffer->offer_name,
                'offerType' => (string) $bestOffer->offer_type,
                'discountValue' => $bestOffer->discount_value !== null ? (float) $bestOffer->discount_value : null,
                'discountPercent' => $bestOffer->discount_percent !== null ? (int) $bestOffer->discount_percent : null,
                'usesCount' => (int) $bestOffer->uses_count,
                'revenue' => (float) $bestOffer->revenue,
                'totalSavings' => (float) $bestOffer->total_savings,
            ] : null,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(string $range, ?string $from, ?string $to): array
    {
        $now = now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'custom' => [
                Carbon::parse((string) $from)->startOfDay(),
                Carbon::parse((string) $to)->endOfDay(),
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
