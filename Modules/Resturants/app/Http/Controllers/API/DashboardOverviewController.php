<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantDailyStat;
use Modules\Resturants\Models\RestaurantOrderDispute;

final class DashboardOverviewController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'restaurantId' => 'required|exists:restaurants,id',
        ]);

        $restaurantId = (int) $request->input('restaurantId');
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $todayOrdersQuery = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today);
        $todayOrders = (clone $todayOrdersQuery)->count();

        $ordersByStatusRaw = (clone $todayOrdersQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $ordersByStatus = [];
        foreach (OrderStatus::cases() as $status) {
            $camelKey = lcfirst(str_replace('_', '', ucwords($status->value, '_')));
            $ordersByStatus[$camelKey] = $ordersByStatusRaw[$status->value] ?? 0;
        }

        $todayTotalSales = (float) Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->where('status', OrderStatus::Completed)
            ->sum('total_amount');

        $yesterdayTotalSales = (float) Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $yesterday)
            ->where('status', OrderStatus::Completed)
            ->sum('total_amount');

        $todayDailyStat = RestaurantDailyStat::query()
            ->where('restaurant_id', $restaurantId)
            ->where('stat_date', $today)
            ->first();
        if ($todayDailyStat && $todayTotalSales === 0.0) {
            $todayTotalSales = (float) $todayDailyStat->revenue;
        }

        $yesterdayDailyStat = RestaurantDailyStat::query()
            ->where('restaurant_id', $restaurantId)
            ->where('stat_date', $yesterday)
            ->first();
        if ($yesterdayDailyStat && $yesterdayTotalSales === 0.0) {
            $yesterdayTotalSales = (float) $yesterdayDailyStat->revenue;
        }

        $salesChangePercent = $yesterdayTotalSales > 0
            ? (int) round((($todayTotalSales - $yesterdayTotalSales) / $yesterdayTotalSales) * 100)
            : ($todayTotalSales > 0 ? 100 : 0);

        $driver = DB::connection()->getDriverName();
        $hourExpr = $driver === 'mysql' ? 'HOUR(created_at)' : "cast(strftime('%H', created_at) as integer)";
        $orderActivityByHour = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->selectRaw("{$hourExpr} as hour, count(*) as count")
            ->groupByRaw($hourExpr)
            ->orderBy('hour')
            ->get()
            ->map(fn ($row) => [
                'hour' => (int) $row->hour,
                'count' => (int) $row->count,
            ])
            ->values()
            ->toArray();

        $openDisputes = RestaurantOrderDispute::query()
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->whereIn('status', [RestaurantDisputeStatus::Open, RestaurantDisputeStatus::UnderReview])
            ->count();

        $ordersPendingPickup = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->where('status', OrderStatus::Preparing)
            ->count();

        $ordersReadyForPickup = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->where('status', OrderStatus::ReadyForPickup)
            ->count();

        $lowStockProductsQuery = Product::query()
            ->where('restaurant_id', $restaurantId)
            ->lowStock()
            ->limit(10);
        $lowStockAlertsCount = Product::query()
            ->where('restaurant_id', $restaurantId)
            ->lowStock()
            ->count();
        $lowStockProducts = $lowStockProductsQuery->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'stockQuantity' => $p->stock_quantity,
            'lowStockThreshold' => $p->low_stock_threshold,
        ])->toArray();

        return response()->json([
            'kpis' => [
                'todayTotalSales' => $todayTotalSales,
                'yesterdayTotalSales' => $yesterdayTotalSales,
                'salesChangePercent' => $salesChangePercent,
                'todayOrders' => $todayOrders,
                'ordersByStatus' => $ordersByStatus,
                'activeRestaurants' => Restaurant::query()->where('id', $restaurantId)->where('is_active', true)->exists() ? 1 : 0,
                'openDisputes' => $openDisputes,
                'ordersPendingPickup' => $ordersPendingPickup,
                'ordersReadyForPickup' => $ordersReadyForPickup,
                'lowStockAlertsCount' => $lowStockAlertsCount,
                'orderActivityByHour' => $orderActivityByHour,
                'lowStockProducts' => $lowStockProducts,
            ],
        ]);
    }
}
