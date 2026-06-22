<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerOfferSummaryController
{
    public function __invoke(RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();
        $now = now();

        $offers = Offer::query()->where('restaurant_id', $restaurant->id)->get();

        $activeCount = $offers->filter(function (Offer $offer) use ($now): bool {
            return $offer->is_active
                && (! $offer->starts_at || $offer->starts_at->lte($now))
                && (! $offer->ends_at || $offer->ends_at->gte($now));
        })->count();

        $expiredCount = $offers->filter(function (Offer $offer) use ($now): bool {
            return ! $offer->is_active || ($offer->ends_at && $offer->ends_at->lt($now));
        })->count();

        $performance = DB::table('offer_product as op')
            ->join('order_items as oi', 'oi.product_id', '=', 'op.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->selectRaw('op.offer_id, COUNT(DISTINCT o.id) as orders_count, COALESCE(SUM(oi.total_price),0) as revenue_impact')
            ->where('o.restaurant_id', $restaurant->id)
            ->groupBy('op.offer_id')
            ->get();

        $ordersCount = (int) $performance->sum('orders_count');
        $revenueImpact = round((float) $performance->sum('revenue_impact'), 2);
        $totalSavings = 0.0;

        $topPerformance = $performance->sortByDesc('orders_count')->first();
        $topOffer = $topPerformance ? $offers->firstWhere('id', (int) $topPerformance->offer_id) : null;
        $topRevenue = $topPerformance ? round((float) $topPerformance->revenue_impact, 2) : 0.0;
        $topOrdersCount = $topPerformance ? (int) $topPerformance->orders_count : 0;

        return response()->json([
            'summary' => [
                'activeCount' => $activeCount,
                'expiredCount' => $expiredCount,
                'totalUsageOrders' => $ordersCount,
                'totalSavings' => $totalSavings,
                'revenueImpact' => $revenueImpact,
                'topPerforming' => $topOffer ? [
                    'id' => $topOffer->id,
                    'name' => $topOffer->name,
                    'ordersCount' => $topOrdersCount,
                    'usageCount' => $topOrdersCount,
                    'revenueImpact' => $topRevenue,
                    'generatedRevenue' => $topRevenue,
                ] : null,
            ],
        ]);
    }
}
