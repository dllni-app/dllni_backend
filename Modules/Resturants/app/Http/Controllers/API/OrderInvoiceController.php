<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\Order;

final class OrderInvoiceController
{
    public function __invoke(Order $order): JsonResponse
    {
        $order->load(['user', 'restaurant', 'orderItems.product']);

        $invoice = [
            'orderNumber' => $order->order_number,
            'orderId' => $order->id,
            'status' => $order->status?->value ?? $order->status,
            'createdAt' => $order->created_at->toDateTimeString(),
            'customer' => [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ],
            'restaurant' => [
                'id' => $order->restaurant->id,
                'name' => $order->restaurant->name,
                'address' => $order->restaurant->address,
            ],
            'items' => $order->orderItems->map(fn ($item) => [
                'productName' => $item->product?->name ?? 'Product',
                'quantity' => $item->quantity,
                'unitPrice' => (float) $item->unit_price,
                'totalPrice' => (float) $item->total_price,
            ])->values()->all(),
            'subtotal' => (float) $order->subtotal,
            'discountAmount' => (float) $order->discount_amount,
            'taxAmount' => (float) $order->tax_amount,
            'serviceFee' => (float) $order->service_fee,
            'totalAmount' => (float) $order->total_amount,
        ];

        return response()->json(['data' => $invoice]);
    }
}
