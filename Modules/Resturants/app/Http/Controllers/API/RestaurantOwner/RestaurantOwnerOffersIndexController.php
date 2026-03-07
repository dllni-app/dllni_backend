<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerOffersIndexRequest;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Support\RestaurantOwnerContext;

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
            ->with('products:id,name')
            ->where('restaurant_id', $restaurant->id);

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
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                });
        } elseif ($status === 'scheduled') {
            $query->whereNotNull('starts_at')->where('starts_at', '>', $now);
        } elseif ($status === 'expired') {
            $query->where(function ($q) use ($now): void {
                $q->where('is_active', false)
                    ->orWhere('ends_at', '<', $now);
            });
        }

        if ($sort === 'performance' || $sort === '-performance') {
            $query->orderBy('discount_value', $sort === 'performance' ? 'asc' : 'desc');
        } else {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $field = ltrim($sort, '-');
            $query->orderBy($field, $direction);
        }

        $paginator = $query->paginate($perPage);
        $offerIds = collect($paginator->items())->pluck('id')->all();

        $performanceRows = DB::table('offer_product as op')
            ->join('order_items as oi', 'oi.product_id', '=', 'op.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->selectRaw('op.offer_id, COUNT(DISTINCT o.id) as orders_count, COALESCE(SUM(oi.total_price),0) as revenue_impact')
            ->whereIn('op.offer_id', $offerIds)
            ->where('o.restaurant_id', $restaurant->id)
            ->groupBy('op.offer_id')
            ->get()
            ->keyBy('offer_id');

        $data = collect($paginator->items())->map(function (Offer $offer) use ($performanceRows): array {
            $performance = $performanceRows->get($offer->id);

            return [
                'id' => $offer->id,
                'restaurantId' => $offer->restaurant_id,
                'name' => $offer->name,
                'discountType' => $offer->discount_type?->value ?? $offer->discount_type,
                'discountValue' => (float) ($offer->discount_value ?? 0),
                'startsAt' => $offer->starts_at?->toDateTimeString(),
                'endsAt' => $offer->ends_at?->toDateTimeString(),
                'isActive' => (bool) $offer->is_active,
                'products' => $offer->products->map(fn ($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                ])->values()->all(),
                'performance' => [
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
