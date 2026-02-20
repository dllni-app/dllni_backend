<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantOrderDispute;

final class DashboardOverviewController
{
    public function __invoke(Request $request): JsonResponse
    {
        $today = Carbon::today();

        $todayOrdersQuery = Order::query()->whereDate('created_at', $today);
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

        $activeRestaurants = Restaurant::query()
            ->where('is_active', true)
            ->count();

        $openDisputes = RestaurantOrderDispute::query()
            ->whereIn('status', [RestaurantDisputeStatus::Open, RestaurantDisputeStatus::UnderReview])
            ->count();

        $ordersPendingPickup = Order::query()
            ->whereDate('created_at', $today)
            ->where('status', OrderStatus::Preparing)
            ->count();

        $ordersReadyForPickup = Order::query()
            ->whereDate('created_at', $today)
            ->where('status', OrderStatus::ReadyForPickup)
            ->count();

        $lowStockAlertsCount = Product::query()
            ->lowStock()
            ->count();

        return response()->json([
            'kpis' => [
                'todayOrders' => $todayOrders,
                'ordersByStatus' => $ordersByStatus,
                'activeRestaurants' => $activeRestaurants,
                'openDisputes' => $openDisputes,
                'ordersPendingPickup' => $ordersPendingPickup,
                'ordersReadyForPickup' => $ordersReadyForPickup,
                'lowStockAlertsCount' => $lowStockAlertsCount,
            ],
        ]);
    }
}
