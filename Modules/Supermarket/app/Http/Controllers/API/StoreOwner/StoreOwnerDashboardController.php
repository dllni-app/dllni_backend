<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerDashboardController
{
    public function __construct(private StoreOwnerContextService $context) {}

    public function __invoke(): JsonResponse
    {
        $storeId = $this->context->ownedStore()->id;
        $today = Carbon::today();

        // Base query for today's orders
        $todayOrdersQuery = SmOrder::query()
            ->where('store_id', $storeId)
            ->whereDate('created_at', $today);

        // Total orders count
        $totalOrders = (clone $todayOrdersQuery)->count();

        // Completed orders count
        $completedOrders = (clone $todayOrdersQuery)
            ->where('status', SmOrderStatus::Completed)
            ->count();

        // New orders (Pending status)
        $newOrdersCount = (clone $todayOrdersQuery)
            ->where('status', SmOrderStatus::Pending)
            ->count();

        // Pending orders (not completed and not cancelled)
        $pendingOrdersCount = (clone $todayOrdersQuery)
            ->whereNotIn('status', [SmOrderStatus::Completed, SmOrderStatus::Cancelled])
            ->count();

        // Total sales (completed orders only)
        $totalSales = (float) (clone $todayOrdersQuery)
            ->where('status', SmOrderStatus::Completed)
            ->sum('total_amount');

        // Get yesterday's total sales for comparison
        $yesterday = Carbon::yesterday();
        $yesterdaySales = (float) SmOrder::query()
            ->where('store_id', $storeId)
            ->where('status', SmOrderStatus::Completed)
            ->whereDate('created_at', $yesterday)
            ->sum('total_amount');

        // Calculate sales percentage change (positive for increase, negative for decrease)
        $salesPercentageChange = $yesterdaySales > 0
            ? (($totalSales - $yesterdaySales) / $yesterdaySales) * 100
            : 0;

        // Get new orders data (Pending)
        $newOrdersData = SmOrder::query()
            ->where('store_id', $storeId)
            ->where('status', SmOrderStatus::Pending)
            ->whereDate('created_at', $today)
            ->with(['customer', 'items.product'])
            ->latest()
            ->get();

        // Get pending orders data (all non-completed, non-cancelled)
        $pendingOrdersData = SmOrder::query()
            ->where('store_id', $storeId)
            ->whereNotIn('status', [SmOrderStatus::Completed, SmOrderStatus::Cancelled])
            ->with(['customer', 'items.product'])
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Dashboard data retrieved successfully.',
            'data' => [
                'totalOrders' => $totalOrders,
                'completedOrders' => $completedOrders,
                'newOrders' => $newOrdersCount,
                'pendingOrders' => $pendingOrdersCount,
                'totalSales' => $totalSales,
                'salesPercentageChange' => (float) round($salesPercentageChange, 2),
            ],
        ]);
    }
}
