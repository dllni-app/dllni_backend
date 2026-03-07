<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;

final class RestaurantOwnerOrderItemService
{
    public function addItem(
        Order $order,
        Product $product,
        int $quantity,
        ?int $substituteProductId = null,
        ?string $specialInstructions = null
    ): Order {
        return DB::transaction(function () use ($order, $product, $quantity, $substituteProductId, $specialInstructions) {
            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);

            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'substitute_product_id' => $substituteProductId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $specialInstructions,
            ]);

            return $this->recalculateTotals($order);
        });
    }

    public function updateItem(
        Order $order,
        OrderItem $item,
        ?int $quantity = null,
        mixed $substituteProductId = null,
        bool $setSubstituteProduct = false,
        mixed $specialInstructions = null,
        bool $setSpecialInstructions = false
    ): Order {
        return DB::transaction(function () use (
            $order,
            $item,
            $quantity,
            $substituteProductId,
            $setSubstituteProduct,
            $specialInstructions,
            $setSpecialInstructions
        ) {
            $updates = [];

            if ($quantity !== null) {
                $updates['quantity'] = $quantity;
                $updates['total_price'] = ((float) $item->unit_price) * $quantity;
            }

            if ($setSubstituteProduct) {
                $updates['substitute_product_id'] = $substituteProductId;
            }

            if ($setSpecialInstructions) {
                $updates['special_instructions'] = $specialInstructions;
            }

            if ($updates !== []) {
                $item->update($updates);
            }

            return $this->recalculateTotals($order);
        });
    }

    public function removeItem(Order $order, OrderItem $item): Order
    {
        return DB::transaction(function () use ($order, $item) {
            $item->delete();

            return $this->recalculateTotals($order);
        });
    }

    public function recalculateTotals(Order $order): Order
    {
        $subtotal = (float) $order->orderItems()->sum('total_price');
        $discountAmount = min((float) ($order->discount_amount ?? 0), $subtotal);
        $taxAmount = (float) ($order->tax_amount ?? 0);
        $serviceFee = (float) ($order->service_fee ?? 0);
        $totalAmount = max(0, $subtotal - $discountAmount + $taxAmount + $serviceFee);

        $order->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
        ]);

        return $order->fresh();
    }
}
