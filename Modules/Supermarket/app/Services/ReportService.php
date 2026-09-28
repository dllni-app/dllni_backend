<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Enums\SmDisputeStatus;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderDispute;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreDailyStat;
use Modules\Supermarket\Models\SmStoreDocument;

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
        $ordersQuery = SmOrder::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when($status, fn ($query) => $query->where('status', $status));

        $orders = (clone $ordersQuery)->get();

        $totalRevenue = (float) $orders->sum('total_amount');
        $serviceFees = (float) $orders->sum('service_fee');
        $commissions = (float) $orders->sum('commission_amount');
        $cancellationFees = (float) $orders->sum('cancellation_fee_amount');
        $storeNetPayable = (float) $orders->sum('merchant_net_amount');
        $platformCouponCost = (float) $orders->sum('platform_coupon_cost');
        $merchantCouponFunding = (float) $orders->sum('coupon_merchant_funded_amount');
        $platformNetRevenue = (float) $orders->sum('platform_net_revenue');
        $unsnapshottedOrders = $orders->whereNull('financial_snapshot')->count();

        $revenueByStore = SmOrder::query()
            ->selectRaw('sm_stores.id, sm_stores.name')
            ->selectRaw('COUNT(sm_orders.id) as total_orders')
            ->selectRaw('COALESCE(SUM(sm_orders.total_amount), 0) as total_revenue')
            ->selectRaw('COALESCE(SUM(sm_orders.service_fee), 0) as total_service_fees')
            ->selectRaw('COALESCE(SUM(sm_orders.commission_amount), 0) as total_commissions')
            ->selectRaw('COALESCE(SUM(sm_orders.cancellation_fee_amount), 0) as total_cancellation_fees')
            ->selectRaw('COALESCE(SUM(sm_orders.merchant_net_amount), 0) as store_net_payable')
            ->selectRaw('COALESCE(SUM(sm_orders.platform_coupon_cost), 0) as platform_coupon_cost')
            ->selectRaw('COALESCE(SUM(sm_orders.coupon_merchant_funded_amount), 0) as merchant_coupon_funding')
            ->selectRaw('COALESCE(SUM(sm_orders.platform_net_revenue), 0) as platform_net_revenue')
            ->selectRaw('SUM(CASE WHEN sm_orders.financial_snapshot IS NULL THEN 1 ELSE 0 END) as unsnapshotted_orders')
            ->leftJoin('sm_stores', 'sm_orders.store_id', '=', 'sm_stores.id')
            ->whereBetween('sm_orders.created_at', [$startDate, $endDate])
            ->when($storeId, fn ($query) => $query->where('sm_orders.store_id', $storeId))
            ->when($status, fn ($query) => $query->where('sm_orders.status', $status))
            ->groupBy('sm_stores.id', 'sm_stores.name')
            ->get()
            ->map(function ($row): array {
                $ordersCount = (int) $row->total_orders;
                $revenue = (float) $row->total_revenue;
                $commission = (float) $row->total_commissions;
                $net = (float) $row->store_net_payable;

                return [
                    'store_id' => $row->id,
                    'store_name' => $row->name,
                    'total_orders' => $ordersCount,
                    'order_count' => $ordersCount,
                    'total_revenue' => $revenue,
                    'gross_sales' => $revenue,
                    'total_service_fees' => (float) $row->total_service_fees,
                    'total_commissions' => $commission,
                    'commission_deducted' => $commission,
                    'total_cancellation_fees' => (float) $row->total_cancellation_fees,
                    'store_net_payable' => $net,
                    'net_payable' => $net,
                    'platform_coupon_cost' => (float) $row->platform_coupon_cost,
                    'merchant_coupon_funding' => (float) $row->merchant_coupon_funding,
                    'platform_net_revenue' => (float) $row->platform_net_revenue,
                    'average_order_value' => $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0.0,
                    'unsnapshotted_orders' => (int) $row->unsnapshotted_orders,
                ];
            })
            ->values();

        $revenueByDate = SmOrder::query()
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(id) as order_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_revenue')
            ->selectRaw('COALESCE(SUM(service_fee), 0) as total_service_fees')
            ->selectRaw('COALESCE(SUM(commission_amount), 0) as total_commissions')
            ->selectRaw('COALESCE(SUM(merchant_net_amount), 0) as store_net_payable')
            ->selectRaw('COALESCE(SUM(platform_coupon_cost), 0) as platform_coupon_cost')
            ->selectRaw('COALESCE(SUM(coupon_merchant_funded_amount), 0) as merchant_coupon_funding')
            ->selectRaw('COALESCE(SUM(platform_net_revenue), 0) as platform_net_revenue')
            ->selectRaw('SUM(CASE WHEN financial_snapshot IS NULL THEN 1 ELSE 0 END) as unsnapshotted_orders')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->date,
                'revenue' => (float) $row->total_revenue,
                'total_revenue' => (float) $row->total_revenue,
                'total_service_fees' => (float) $row->total_service_fees,
                'total_commissions' => (float) $row->total_commissions,
                'store_net_payable' => (float) $row->store_net_payable,
                'platform_coupon_cost' => (float) $row->platform_coupon_cost,
                'merchant_coupon_funding' => (float) $row->merchant_coupon_funding,
                'platform_net_revenue' => (float) $row->platform_net_revenue,
                'orders_count' => (int) $row->order_count,
                'order_count' => (int) $row->order_count,
                'unsnapshotted_orders' => (int) $row->unsnapshotted_orders,
            ])
            ->values();

        return [
            'overview' => [
                'total_revenue' => $totalRevenue,
                'total_service_fees' => $serviceFees,
                'total_commissions' => $commissions,
                'total_cancellation_fees' => $cancellationFees,
                'store_net_payable' => $storeNetPayable,
                'platform_coupon_cost' => $platformCouponCost,
                'merchant_coupon_funding' => $merchantCouponFunding,
                'platform_net_revenue' => $platformNetRevenue,
                'unsnapshotted_orders' => $unsnapshottedOrders,
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
            ->when($storeId, fn ($q) => $q->where('sm_orders.store_id', $storeId))
            ->groupBy('sm_products.id', 'sm_products.name')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
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
            ->map(fn ($row) => [
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
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
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
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $thisWeek = $now->copy()->startOfWeek();
        $thisMonth = $now->copy()->startOfMonth();

        // Sales summary - current periods (inclusive of the current moment)
        $todayOrders = SmOrder::whereDate('created_at', $today)->get();
        $weekOrders = SmOrder::whereBetween('created_at', [$thisWeek, $now])->get();
        $monthOrders = SmOrder::whereBetween('created_at', [$thisMonth, $now])->get();

        // Sales summary - yesterday comparison
        $yesterday = Carbon::yesterday();
        $yesterdayOrders = SmOrder::whereDate('created_at', $yesterday)->get();

        // Calculate sales totals
        $todaySales = (float) $todayOrders->sum('total_amount');
        $thisWeekSales = (float) $weekOrders->sum('total_amount');
        $thisMonthSales = (float) $monthOrders->sum('total_amount');
        $yesterdaySales = (float) $yesterdayOrders->sum('total_amount');

        // Calculate percentage change (today vs yesterday only)
        $todayPercentageChange = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 2)
            : ($todaySales > 0 ? 100.0 : 0.0);

        // Activity metrics
        $totalStores = SmStore::count();
        $activeStores = SmStore::where('is_active', true)->count();
        $pendingPickupOrders = SmOrder::where('status', SmOrderStatus::ReadyForPickup->value)->count();

        $queueCounts = $this->buildQueueCounts(CarbonImmutable::now());

        $highCancellationStores = DB::table('sm_orders')
            ->selectRaw('store_id')
            ->whereDate('created_at', '>=', Carbon::today()->subDays(7))
            ->groupBy('store_id')
            ->havingRaw('(SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) / COUNT(*)) > 0.2')
            ->count();

        // Recent activity
        $recentOrders = SmOrder::with('store', 'customer')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'store_name' => $order->store?->name,
                'customer_name' => $order->customer?->name,
                'order_total' => (float) $order->total_amount,
                'status' => $order->status,
                'created_at' => $order->created_at->toIso8601String(),
            ])
            ->values();

        return [
            'sales_summary' => [
                'today' => $todaySales,
                'today_percentage_change' => $todayPercentageChange,
                'this_week' => $thisWeekSales,
                'this_month' => $thisMonthSales,
                'total_commission_revenue' => (float) $monthOrders->sum('commission_amount'),
                'total_service_fees' => (float) $monthOrders->sum('service_fee'),
                'unsnapshotted_orders' => $monthOrders->whereNull('commission_snapshot')->count(),
            ],
            'activity_metrics' => [
                'total_orders' => SmOrder::count(),
                'active_stores' => $activeStores,
                'total_stores' => $totalStores,
                'pending_pickup_orders' => $pendingPickupOrders,
            ],
            'operational_alerts' => [
                'low_stock_products_count' => $queueCounts['low_stock_products'],
                'high_cancellation_stores_count' => $highCancellationStores,
                'open_disputes_count' => $queueCounts['open_disputes'],
            ],
            'queue_counts' => $queueCounts,
            'recent_activity' => $recentOrders,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildQueueCounts(CarbonImmutable $now): array
    {
        $expiringEnd = $now->addDays(3);

        return [
            'pending_documents' => SmStoreDocument::query()->where('verification_status', 'pending')->count(),
            'open_disputes' => SmOrderDispute::query()
                ->whereIn('status', [SmDisputeStatus::Open->value, SmDisputeStatus::UnderReview->value])
                ->count(),
            'pending_pickup_orders' => SmOrder::query()->where('status', SmOrderStatus::ReadyForPickup->value)->count(),
            'suspended_stores' => SmStore::query()
                ->whereNotNull('suspension_until')
                ->where('suspension_until', '>', $now)
                ->count(),
            'low_stock_products' => $this->countLowStockProducts(),
            'expiring_promotions' => SmOffer::query()
                ->where('is_active', true)
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [$now, $expiringEnd])
                ->count()
                + SmCoupon::query()
                    ->where('is_active', true)
                    ->whereNotNull('ends_at')
                    ->whereBetween('ends_at', [$now, $expiringEnd])
                    ->count(),
        ];
    }

    private function countLowStockProducts(): int
    {
        return SmProduct::query()
            ->where('is_available', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();
    }
}
