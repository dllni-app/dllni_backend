<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerCouponsIndexRequest;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerCouponsIndexController
{
    public function __invoke(OwnerCouponsIndexRequest $request, RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();
        $now = now();
        $status = $request->string('status')->toString() ?: 'all';
        $perPage = (int) $request->integer('perPage', 10);
        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString() ?: '-created_at';

        $query = PromoCode::query()->where('restaurant_id', $restaurant->id);

        if ($search !== '') {
            $query->where('code', 'like', '%'.$search.'%');
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
            $query->orderBy('usage_count', $sort === 'performance' ? 'asc' : 'desc');
        } else {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $field = mb_ltrim($sort, '-');
            $query->orderBy($field, $direction);
        }

        $paginator = $query->paginate($perPage);
        $couponIds = collect($paginator->items())->pluck('id')->all();
        $orderStats = DB::table('orders')
            ->selectRaw('promo_code_id, COUNT(*) as orders_count, COALESCE(SUM(discount_amount),0) as savings, COALESCE(SUM(total_amount),0) as revenue')
            ->whereIn('promo_code_id', $couponIds)
            ->groupBy('promo_code_id')
            ->get()
            ->keyBy('promo_code_id');

        $data = collect($paginator->items())->map(function (PromoCode $coupon) use ($orderStats): array {
            $stats = $orderStats->get($coupon->id);

            return [
                'id' => $coupon->id,
                'restaurantId' => $coupon->restaurant_id,
                'code' => $coupon->code,
                'discountType' => $coupon->discount_type?->value ?? $coupon->discount_type,
                'discountValue' => (float) ($coupon->discount_value ?? 0),
                'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'usageLimit' => $coupon->usage_limit,
                'usageCount' => (int) $coupon->usage_count,
                'startsAt' => $coupon->starts_at?->toDateTimeString(),
                'endsAt' => $coupon->ends_at?->toDateTimeString(),
                'isActive' => (bool) $coupon->is_active,
                'performance' => [
                    'ordersCount' => (int) ($stats->orders_count ?? 0),
                    'totalSavings' => (float) ($stats->savings ?? 0),
                    'revenueImpact' => (float) ($stats->revenue ?? 0),
                ],
                'createdAt' => $coupon->created_at?->toDateTimeString(),
                'updatedAt' => $coupon->updated_at?->toDateTimeString(),
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
