<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantPromotionCapability;

final class RestaurantOwnerOfferSummaryController
{
    public function __invoke(RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();
        $offers = Offer::query()->where('restaurant_id', $restaurant->id)
            ->when(! RestaurantPromotionCapability::clientSupports(), fn ($q) => $q->where('offer_type', 'product_discount'))->get();
        $statuses = $offers->countBy(fn (Offer $offer): string => $offer->effectiveStatus());
        $ledger = DB::table('restaurant_order_promotion_applications')
            ->where('restaurant_id', $restaurant->id)->where('status', 'applied');
        if (! RestaurantPromotionCapability::clientSupports()) {
            $ledger->whereIn('original_offer_id', $offers->pluck('id'));
        }
        $totals = (clone $ledger)->selectRaw('COUNT(DISTINCT order_id) as orders_count, COALESCE(SUM(discount_amount),0) as savings, COALESCE(SUM(net_eligible_revenue),0) as revenue, COALESCE(SUM(application_count),0) as applications')->first();
        $top = (clone $ledger)->selectRaw('original_offer_id, COUNT(DISTINCT order_id) as orders_count, SUM(net_eligible_revenue) as revenue')
            ->groupBy('original_offer_id')->orderByDesc('orders_count')->orderBy('original_offer_id')->first();
        $topOffer = $top ? $offers->firstWhere('id', (int) $top->original_offer_id) : null;

        return response()->json(['summary' => [
            'advancedPromotionsEnabled' => RestaurantPromotionCapability::supported(),
            'activeCount' => (int) ($statuses['active'] ?? 0),
            'expiredCount' => (int) ($statuses['expired'] ?? 0),
            'scheduledCount' => (int) ($statuses['scheduled'] ?? 0),
            'pausedCount' => (int) ($statuses['paused'] ?? 0),
            'totalUsageOrders' => (int) $totals->orders_count,
            'totalSavings' => (float) $totals->savings,
            'revenueImpact' => (float) $totals->revenue,
            'applications' => (int) $totals->applications,
            'attributionScope' => 'recorded_promotions_only',
            'topPerforming' => $topOffer ? [
                'id' => $topOffer->id, 'name' => $topOffer->name,
                'ordersCount' => (int) $top->orders_count, 'usageCount' => (int) $top->orders_count,
                'revenueImpact' => (float) $top->revenue, 'generatedRevenue' => (float) $top->revenue,
            ] : null,
        ]]);
    }
}