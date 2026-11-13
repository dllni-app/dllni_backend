<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerOffersIndexRequest;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantPromotionCapability;

final class RestaurantOwnerOffersIndexController
{
    public function __invoke(OwnerOffersIndexRequest $request, RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();
        $now = now();
        $status = $request->string('status')->toString() ?: 'all';
        $perPage = (int) $request->integer('perPage', 10);
        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString() ?: '-created_at';

        $query = Offer::query()
            ->with(['products:id,name', 'rewardProducts:id,name'])
            ->where('restaurant_id', $restaurant->id)
            ->when(! RestaurantPromotionCapability::clientSupports(), fn ($q) => $q->where('offer_type', 'product_discount'));

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if ($request->filled('dateFrom')) {
            $query->where('starts_at', '>=', $request->date('dateFrom')?->startOfDay());
        }

        if ($request->filled('dateTo')) {
            $query->where('ends_at', '<=', $request->date('dateTo')?->endOfDay());
        }

        if ($status === 'active') {
            $query->where('is_active', true)
                ->where(function ($q) use ($now): void {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($q) use ($now): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                });
        } elseif ($status === 'scheduled') {
            $query->where('is_active', true)->whereNotNull('starts_at')->where('starts_at', '>', $now);
        } elseif ($status === 'expired') {
            $query->where('is_active', true)->where('ends_at', '<=', $now);
        } elseif ($status === 'paused') {
            $query->where('is_active', false);
        }

        if ($sort === 'performance' || $sort === '-performance') {
            $query->orderBy(DB::table('restaurant_order_promotion_applications as pa')->selectRaw('COUNT(*)')->whereColumn('pa.original_offer_id', 'offers.id')->where('pa.status', 'applied'), $sort === 'performance' ? 'asc' : 'desc');
        } else {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $field = mb_ltrim($sort, '-');
            $query->orderBy($field, $direction);
        }

        $paginator = $query->paginate($perPage);
        $offerIds = collect($paginator->items())->pluck('id')->all();

        $performanceRows = DB::table('restaurant_order_promotion_applications')
            ->selectRaw('original_offer_id, COUNT(DISTINCT order_id) as orders_count, SUM(net_eligible_revenue) as revenue_impact, SUM(discount_amount) as savings, SUM(application_count) as applications, SUM(free_quantity) as free_quantity')
            ->whereIn('original_offer_id', $offerIds)->where('restaurant_id', $restaurant->id)->where('status', 'applied')
            ->groupBy('original_offer_id')->get()->keyBy('original_offer_id');

        $data = collect($paginator->items())->map(function (Offer $offer) use ($performanceRows): array {
            $performance = $performanceRows->get($offer->id);

            return [
                ...$offer->promotionAttributes(),
                'id' => $offer->id,
                'restaurantId' => $offer->restaurant_id,
                'name' => $offer->name,
                'discountType' => $offer->discount_type?->value ?? $offer->discount_type,
                'discountValue' => (float) ($offer->discount_value ?? 0),
                'startsAt' => $offer->isProductDiscount() ? $offer->starts_at?->toDateTimeString() : $offer->starts_at?->toIso8601String(),
                'endsAt' => $offer->isProductDiscount() ? $offer->ends_at?->toDateTimeString() : $offer->ends_at?->toIso8601String(),
                'isActive' => (bool) $offer->is_active,
                'startsAtIso' => $offer->starts_at?->toIso8601String(),
                'endsAtIso' => $offer->ends_at?->toIso8601String(),
                'products' => $offer->products->map(fn ($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                ])->values()->all(),
                'rewardProducts' => $offer->rewardProducts->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
                'performance' => [
                    'attributionAvailable' => ! $offer->isProductDiscount(),
                    'totalSavings' => (float) ($performance->savings ?? 0),
                    'applications' => (int) ($performance->applications ?? 0),
                    'freeQuantity' => (int) ($performance->free_quantity ?? 0),
                    'ordersCount' => (int) ($performance->orders_count ?? 0),
                    'revenueImpact' => (float) ($performance->revenue_impact ?? 0),
                ],
                'createdAt' => $offer->created_at?->toDateTimeString(),
                'updatedAt' => $offer->updated_at?->toDateTimeString(),
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}