<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class ReportService
{
    /**
     * Get financial report data
     *
     * @return array<string, mixed>
     */
    public function getFinancialReport(
        Carbon $startDate,
        Carbon $endDate,
        ?int $storeId = null,
        ?string $status = null,
    ): array {
        // Revenue overview
        $ordersQuery = SmOrder::whereBetween('created_at', [$startDate, $endDate]);

        if ($storeId) {
            $ordersQuery->where('store_id', $storeId);
        }

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        $orders = $ordersQuery->get();

        $totalRevenue = $orders->sum('total_amount');
        $serviceFees = $orders->sum('service_fee');
        $commissions = $orders->sum('commission_amount');
        $cancellationFees = $orders->sum('cancellation_fee_amount');

        // Revenue by store
        $revenueByStore = SmOrder::selectRaw('sm_stores.id, sm_stores.name, COUNT(sm_orders.id) as total_orders, SUM(sm_orders.total_amount) as gross_sales')
            ->leftJoin('sm_stores', 'sm_orders.store_id', '=', 'sm_stores.id')
            ->whereBetween('sm_orders.created_at', [$startDate, $endDate])
            ->when($status, fn($q) => $q->where('sm_orders.status', $status))
            ->groupBy('sm_stores.id', 'sm_stores.name')
            ->get()
            ->map(fn($row) => [
                'store_id' => $row->id,
                'store_name' => $row->name,
                'total_orders' => $row->total_orders,
                'gross_sales' => (float) $row->gross_sales,
                'commission_deducted' => 0, // TODO: implement when commission structure is defined
                'net_payable' => (float) $row->gross_sales,
            ])
            ->values();

        // Revenue by date
        $revenueByDate = SmStoreDailyStat::selectRaw('date, SUM(orders_revenue) as revenue, SUM(orders_count) as orders_count')
            ->whereBetween('date', [$startDate, $endDate])
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'revenue' => (float) $row->revenue,
                'orders_count' => (int) $row->orders_count,
            ])
            ->values();

        return [
            'overview' => [
                'total_revenue' => (float) $totalRevenue,
                'total_service_fees' => (float) $serviceFees,
                'total_commissions' => (float) $commissions,
                'total_cancellation_fees' => (float) $cancellationFees,
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
            ],
            'by_store' => $revenueByStore,
            'by_date' => $revenueByDate,
        ];
    }

    /**
     * Get performance analytics data
     *
     * @return array<string, mixed>
     */
    public function getPerformanceAnalytics(
        Carbon $startDate,
        Carbon $endDate,
        ?int $storeId = null,
    ): array {
        $ordersQuery = SmOrder::whereBetween('created_at', [$startDate, $endDate]);

        if ($storeId) {
            $ordersQuery->where('store_id', $storeId);
        }

        $orders = $ordersQuery->get();
        $totalOrders = $orders->count();
        $completedOrders = $orders->where('status', 'completed')->count();
        $cancelledOrders = $orders->where('status', 'cancelled')->count();

        // Top performing products
        $topProducts = DB::table('sm_order_items')
            ->selectRaw('sm_products.id, sm_products.name, COUNT(sm_order_items.id) as order_count, SUM(sm_order_items.quantity) as total_quantity, SUM(sm_order_items.total_price) as revenue')
            ->leftJoin('sm_products', 'sm_order_items.product_id', '=', 'sm_products.id')
            ->leftJoin('sm_orders', 'sm_order_items.order_id', '=', 'sm_orders.id')
            ->whereBetween('sm_orders.created_at', [$startDate, $endDate])
            ->when($storeId, fn($q) => $q->where('sm_orders.store_id', $storeId))
            ->groupBy('sm_products.id', 'sm_products.name')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'product_id' => $row->id,
                'product_name' => $row->name,
                'order_count' => (int) $row->order_count,
                'total_quantity' => (int) $row->total_quantity,
                'revenue' => (float) $row->revenue,
            ])
            ->values();

        // Top performing stores
        $topStores = DB::table('sm_orders')
            ->selectRaw('sm_stores.id, sm_stores.name, COUNT(sm_orders.id) as completed_orders, SUM(sm_orders.total_amount) as revenue')
            ->leftJoin('sm_stores', 'sm_orders.store_id', '=', 'sm_stores.id')
            ->whereBetween('sm_orders.created_at', [$startDate, $endDate])
            ->where('sm_orders.status', 'completed')
            ->groupBy('sm_stores.id', 'sm_stores.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'store_id' => $row->id,
                'store_name' => $row->name,
                'completed_orders' => (int) $row->completed_orders,
                'revenue' => (float) $row->revenue,
            ])
            ->values();

        // Operational metrics
        $avgBasketValue = $totalOrders > 0 ? $orders->sum('total_amount') / $totalOrders : 0;
        $completionRate = $totalOrders > 0 ? ($completedOrders / $totalOrders) * 100 : 0;
        $cancellationRate = $totalOrders > 0 ? ($cancelledOrders / $totalOrders) * 100 : 0;

        // Trend data
        $trendData = SmStoreDailyStat::selectRaw('date, SUM(orders_count) as orders_count, SUM(orders_revenue) as revenue')
            ->whereBetween('date', [$startDate, $endDate])
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'orders_count' => (int) $row->orders_count,
                'revenue' => (float) $row->revenue,
            ])
            ->values();

        return [
            'top_products' => $topProducts,
            'top_stores' => $topStores,
            'operational_metrics' => [
                'average_basket_value' => (float) $avgBasketValue,
                'completion_rate' => (float) round($completionRate, 2),
                'cancellation_rate' => (float) round($cancellationRate, 2),
                'total_orders' => $totalOrders,
            ],
            'trends' => $trendData,
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Get main dashboard data
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(): array
    {
        $today = Carbon::today();
        $thisWeek = Carbon::today()->startOfWeek();
        $thisMonth = Carbon::today()->startOfMonth();

        // Sales summary
        $todayOrders = SmOrder::whereDate('created_at', $today)->get();
        $weekOrders = SmOrder::whereBetween('created_at', [$thisWeek, Carbon::today()])->get();
        $monthOrders = SmOrder::whereBetween('created_at', [$thisMonth, Carbon::today()])->get();

        // Activity metrics
        $totalStores = SmStore::count();
        $activeStores = SmStore::where('is_active', true)->count();
        $pendingPickupOrders = SmOrder::where('status', 'ready_for_pickup')->count();

        // Operational alerts
        $lowStockProducts = 0; // TODO: implement when sm_products has inventory tracking
        $highCancellationStores = DB::table('sm_orders')
            ->selectRaw('store_id')
            ->whereDate('created_at', '>=', Carbon::today()->subDays(7))
            ->groupBy('store_id')
            ->havingRaw('(SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) / COUNT(*)) > 0.2')
            ->count();
        $openDisputes = DB::table('sm_order_disputes')
            ->where('status', 'open')
            ->count();

        // Recent activity
        $recentOrders = SmOrder::with('store', 'customer')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn($order) => [
                'id' => $order->id,
                'store_name' => $order->store?->name,
                'customer_name' => $order->customer?->name,
                'order_total' => (float) $order->total_amount,
                'status' => $order->status,
                'created_at' => $order->created_at->toIso8601String(),
            ])
            ->values();

        return [
            'sales_summary' => [
                'today' => (float) $todayOrders->sum('total_amount'),
                'this_week' => (float) $weekOrders->sum('total_amount'),
                'this_month' => (float) $monthOrders->sum('total_amount'),
                'total_commission_revenue' => (float) $monthOrders->sum('commission_amount'),
                'total_service_fees' => (float) $monthOrders->sum('service_fee'),
            ],
            'activity_metrics' => [
                'total_orders' => SmOrder::count(),
                'active_stores' => $activeStores,
                'total_stores' => $totalStores,
                'pending_pickup_orders' => $pendingPickupOrders,
            ],
            'operational_alerts' => [
                'low_stock_products_count' => $lowStockProducts,
                'high_cancellation_stores_count' => $highCancellationStores,
                'open_disputes_count' => $openDisputes,
            ],
            'recent_activity' => $recentOrders,
        ];
    }
}
