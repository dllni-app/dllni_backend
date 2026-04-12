<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Http\Requests\DashboardOverviewRequest;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantOrderDispute;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class DashboardOverviewController
{
    public function __invoke(DashboardOverviewRequest $request, RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $request->has('restaurantId')
            ? Restaurant::query()->findOrFail($request->validated('restaurantId'))
            : $context->restaurant();
        $restaurantId = (int) $restaurant->id;
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $baseOrderQuery = fn () => Order::query()->where('restaurant_id', $restaurantId);
        $completedStatus = OrderStatus::Completed->value;
        $preparingStatus = OrderStatus::Preparing->value;
        $readyForPickupStatus = OrderStatus::ReadyForPickup->value;

        $todayOrdersQuery = $baseOrderQuery()->whereDate('created_at', $today);
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

        $todayTotalSales = (float) (clone $baseOrderQuery())
            ->whereDate('created_at', $today)
            ->where('status', $completedStatus)
            ->sum('total_amount');

        $yesterdayTotalSales = (float) (clone $baseOrderQuery())
            ->whereDate('created_at', $yesterday)
            ->where('status', $completedStatus)
            ->sum('total_amount');

        $salesChangePercent = $yesterdayTotalSales > 0
            ? (int) round((($todayTotalSales - $yesterdayTotalSales) / $yesterdayTotalSales) * 100)
            : ($todayTotalSales > 0 ? 100 : 0);

        $activeRestaurants = $restaurant->is_active ? 1 : 0;

        $openDisputes = RestaurantOrderDispute::query()
            ->restaurantId($restaurantId)
            ->whereIn('status', [
                RestaurantDisputeStatus::Open->value,
                RestaurantDisputeStatus::UnderReview->value,
            ])
            ->count();

        $ordersPendingPickup = (clone $baseOrderQuery())
            ->whereDate('created_at', $today)
            ->where('status', $preparingStatus)
            ->count();

        $ordersReadyForPickup = (clone $baseOrderQuery())
            ->whereDate('created_at', $today)
            ->where('status', $readyForPickupStatus)
            ->count();

        $lowStockBaseQuery = Product::query()
            ->where('restaurant_id', $restaurantId)
            ->lowStock();

        $lowStockAlertsCount = (clone $lowStockBaseQuery)->count();
        $lowStockProducts = (clone $lowStockBaseQuery)
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'stockQuantity' => $p->stock_quantity,
                'lowStockThreshold' => $p->low_stock_threshold,
            ])
            ->values()
            ->all();

        $ordersByHour = (clone $baseOrderQuery())
            ->whereDate('created_at', $today)
            ->get('created_at')
            ->groupBy(fn ($o) => (int) $o->created_at->format('G'))
            ->map(fn ($group) => $group->count());

        $orderActivityByHourFormatted = [];
        for ($h = 0; $h < 24; $h++) {
            $orderActivityByHourFormatted[] = [
                'hour' => $h,
                'count' => $ordersByHour[$h] ?? 0,
            ];
        }

        return response()->json([
            'kpis' => [
                'todayTotalSales' => $todayTotalSales,
                'yesterdayTotalSales' => $yesterdayTotalSales,
                'salesChangePercent' => $salesChangePercent,
                'todayOrders' => $todayOrders,
                'ordersByStatus' => $ordersByStatus,
                'activeRestaurants' => $activeRestaurants,
                'openDisputes' => $openDisputes,
                'ordersPendingPickup' => $ordersPendingPickup,
                'ordersReadyForPickup' => $ordersReadyForPickup,
                'lowStockAlertsCount' => $lowStockAlertsCount,
                'orderActivityByHour' => $orderActivityByHourFormatted,
                'lowStockProducts' => $lowStockProducts,
            ],
        ]);
    }
}
