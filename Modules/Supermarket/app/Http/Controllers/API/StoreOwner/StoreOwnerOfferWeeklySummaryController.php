<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Models\SmOffer;

final class StoreOwnerOfferWeeklySummaryController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'storeId' => ['required', 'integer', 'exists:sm_stores,id'],
        ]);

        $storeId = (int) $request->input('storeId');
        $weekStart = now()->startOfWeek(Carbon::SATURDAY)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
        $now = now();

        $daysOfWeek = [
            'saturday',
            'sunday',
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
        ];

        $ordersUsedByDay = DB::table('sm_orders as sm_order')
            ->join('sm_order_items as sm_order_item', 'sm_order_item.order_id', '=', 'sm_order.id')
            ->join('sm_offer_products as sm_offer_product', 'sm_offer_product.product_id', '=', 'sm_order_item.product_id')
            ->join('sm_offers as sm_offer', function ($join): void {
                $join->on('sm_offer.id', '=', 'sm_offer_product.offer_id')
                    ->on('sm_offer.store_id', '=', 'sm_order.store_id');
            })
            ->where('sm_order.store_id', $storeId)
            ->whereBetween('sm_order.created_at', [$weekStart, $weekEnd])
            ->where(function ($query): void {
                $query->whereNull('sm_offer.starts_at')
                    ->orWhereColumn('sm_offer.starts_at', '<=', 'sm_order.created_at');
            })
            ->where(function ($query): void {
                $query->whereNull('sm_offer.ends_at')
                    ->orWhereColumn('sm_offer.ends_at', '>=', 'sm_order.created_at');
            })
            ->selectRaw('DATE(sm_order.created_at) as day_date, COUNT(DISTINCT sm_order.id) as orders_count')
            ->groupBy('day_date')
            ->pluck('orders_count', 'day_date');

        $series = [];
        $totals = [
            'activeOffers' => 0,
            'scheduledOffers' => 0,
            'ordersUsedOffers' => 0,
            'endedOffers' => 0,
        ];

        foreach ($daysOfWeek as $index => $dayName) {
            $dayStart = $weekStart->copy()->addDays($index)->startOfDay();
            $dayEnd = $dayStart->copy()->endOfDay();

            $activeOffers = SmOffer::query()
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->where(function ($query) use ($dayEnd): void {
                    $query->whereNull('starts_at')
                        ->orWhere('starts_at', '<=', $dayEnd);
                })
                ->where(function ($query) use ($dayStart): void {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>=', $dayStart);
                })
                ->count();

            $scheduledOffers = SmOffer::query()
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->whereNotNull('starts_at')
                ->where('starts_at', '>', $dayEnd)
                ->count();

            $ordersUsedOffers = (int) ($ordersUsedByDay[$dayStart->toDateString()] ?? 0);

            $series[] = [
                'day' => $dayName,
                'activeOffers' => $activeOffers,
                'scheduledOffers' => $scheduledOffers,
                'ordersUsedOffers' => $ordersUsedOffers,
            ];

            $totals['activeOffers'] += $activeOffers;
            $totals['scheduledOffers'] += $scheduledOffers;
            $totals['ordersUsedOffers'] += $ordersUsedOffers;
        }

        $totals['endedOffers'] = SmOffer::query()
            ->where('store_id', $storeId)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$weekStart, $weekEnd])
            ->where('ends_at', '<', $now)
            ->count();

        return response()->json([
            'message' => 'Weekly offers analytics retrieved successfully.',
            'data' => [
                'week' => [
                    'startDate' => $weekStart->toDateString(),
                    'endDate' => $weekEnd->toDateString(),
                    'weekStartsOn' => 'saturday',
                ],
                'series' => $series,
                'totals' => $totals,
            ],
        ]);
    }
}
