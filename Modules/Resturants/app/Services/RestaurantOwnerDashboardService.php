<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantOrderDispute;

final class RestaurantOwnerDashboardService
{
    public function performance(Restaurant $restaurant, string $range, ?string $from, ?string $to): array
    {
        [$start, $end] = $this->resolveRange($range, $from, $to);

        $ordersQuery = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereBetween('created_at', [$start, $end]);

        $totalOrders = (clone $ordersQuery)->count();
        $newOrdersCount = (clone $ordersQuery)->where('status', OrderStatus::Pending)->count();
        $confirmedOrdersCount = (clone $ordersQuery)->where('status', OrderStatus::Accepted)->count();
        $completedOrdersCount = (clone $ordersQuery)->where('status', OrderStatus::Completed)->count();
        $cancelledOrders = (clone $ordersQuery)->where('status', OrderStatus::Cancelled)->count();
        $totalRevenue = (float) (clone $ordersQuery)->where('status', OrderStatus::Completed)->sum('total_amount');
        $averageOrderValue = $completedOrdersCount > 0 ? round($totalRevenue / $completedOrdersCount, 2) : 0.0;
        $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0.0;

        $topProducts = OrderItem::query()
            ->selectRaw('products.id as product_id, products.name as product_name, SUM(order_items.quantity) as quantity_sold, SUM(order_items.total_price) as revenue')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.restaurant_id', $restaurant->id)
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'productId' => (int) $row->product_id,
                'name' => $row->product_name,
                'quantity' => (int) $row->quantity_sold,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();

        $fulfillmentOrders = (clone $ordersQuery)
            ->whereIn('status', [
                OrderStatus::Accepted->value,
                OrderStatus::Preparing->value,
                OrderStatus::ReadyForPickup->value,
                OrderStatus::PickedUp->value,
                OrderStatus::Completed->value,
            ])
            ->get();

        $avgPrepMinutes = $this->averageMinutes(
            $fulfillmentOrders->filter(fn (Order $order) => $order->accepted_at && $order->ready_for_pickup_at),
            fn (Order $order): int => $order->accepted_at->diffInMinutes($order->ready_for_pickup_at)
        );

        $avgReadyToPickupMinutes = $this->averageMinutes(
            $fulfillmentOrders->filter(
                fn (Order $order) => $order->ready_for_pickup_at && ($order->picked_up_at || $order->completed_at)
            ),
            fn (Order $order): int => $order->ready_for_pickup_at->diffInMinutes($order->picked_up_at ?? $order->completed_at)
        );

        $measurableOrders = $fulfillmentOrders->filter(fn (Order $order) => $order->accepted_at && $order->estimated_preparation_minutes);
        $delayedCount = $measurableOrders->filter(function (Order $order): bool {
            $expectedReadyAt = $order->accepted_at->copy()->addMinutes((int) $order->estimated_preparation_minutes);

            if ($order->ready_for_pickup_at) {
                return $order->ready_for_pickup_at->greaterThan($expectedReadyAt);
            }

            return now()->greaterThan($expectedReadyAt) && ! in_array($order->status?->value ?? $order->status, [OrderStatus::Cancelled->value], true);
        })->count();

        $measurableCount = $measurableOrders->count();
        $delayedPercent = $measurableCount > 0 ? round(($delayedCount / $measurableCount) * 100, 2) : 0.0;
        $onTimePercent = $measurableCount > 0 ? round(100 - $delayedPercent, 2) : 0.0;

        $discountedOrdersQuery = (clone $ordersQuery)->where(function ($query): void {
            $query->whereNotNull('promo_code_id')
                ->orWhere('discount_amount', '>', 0);
        });

        $discountedOrdersCount = (clone $discountedOrdersQuery)->count();
        $discountedRevenue = (float) (clone $discountedOrdersQuery)->sum('total_amount');
        $totalSavings = (float) (clone $discountedOrdersQuery)->sum('discount_amount');
        $conversionRate = $totalOrders > 0 ? round(($discountedOrdersCount / $totalOrders) * 100, 2) : 0.0;
        $disputesCount = RestaurantOrderDispute::query()
            ->whereHas('order', function ($query) use ($restaurant): void {
                $query->where('restaurant_id', $restaurant->id);
            })
            ->whereBetween('created_at', [$start, $end])
            ->count();

        return [
            'range' => [
                'key' => $range,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'summary' => [
                'totalOrders' => $totalOrders,
                'newOrdersCount' => $newOrdersCount,
                'confirmedOrdersCount' => $confirmedOrdersCount,
                'completedOrdersCount' => $completedOrdersCount,
                'cancelledOrdersCount' => $cancelledOrders,
                'disputesCount' => $disputesCount,
                'totalRevenue' => $totalRevenue,
                'averageOrderValue' => $averageOrderValue,
                'cancellationRatePercent' => $cancellationRate,
            ],
            'topProducts' => $topProducts,
            'fulfillment' => [
                'averagePrepTimeMinutes' => $avgPrepMinutes,
                'averageReadyToPickupMinutes' => $avgReadyToPickupMinutes,
                'delayedOrdersPercent' => $delayedPercent,
                'onTimePercent' => $onTimePercent,
            ],
            'offersImpact' => [
                'discountedOrdersCount' => $discountedOrdersCount,
                'conversionRatePercent' => $conversionRate,
                'discountedRevenue' => $discountedRevenue,
                'totalSavings' => $totalSavings,
            ],
            'bestOfferPerformance' => [
                'usesCount' => $discountedOrdersCount,
                'revenue' => $discountedRevenue,
            ],
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(string $range, ?string $from, ?string $to): array
    {
        $now = now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'custom' => [
                Carbon::parse((string) $from)->startOfDay(),
                Carbon::parse((string) $to)->endOfDay(),
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /** @param Collection<int, Order> $orders */
    private function averageMinutes(Collection $orders, callable $valueResolver): float
    {
        if ($orders->isEmpty()) {
            return 0.0;
        }

        $sum = $orders->reduce(
            fn (float $carry, Order $order): float => $carry + (float) $valueResolver($order),
            0.0
        );

        return round($sum / $orders->count(), 2);
    }
}
