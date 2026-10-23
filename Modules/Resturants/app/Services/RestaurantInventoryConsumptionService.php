<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Order;

/** Tracks ingredient consumption so an order cancellation can restore exactly what was used. */
final class RestaurantInventoryConsumptionService
{
    /** Must be called inside the transaction holding the restaurant order row lock. */
    public function consume(Order $order): void
    {
        $requirements = [];
        foreach ($order->orderItems()->get() as $line) {
            $productId = (int) ($line->substitute_product_id ?: $line->product_id);
            $items = DB::table('inventory_item_product')
                ->join('inventory_items', 'inventory_items.id', '=', 'inventory_item_product.inventory_item_id')
                ->where('inventory_item_product.product_id', $productId)
                ->where('inventory_items.restaurant_id', $order->restaurant_id)
                ->select('inventory_item_product.inventory_item_id', 'inventory_item_product.quantity_used')
                ->get();
            foreach ($items as $item) {
                $id = (int) $item->inventory_item_id;
                $requirements[$id] = ($requirements[$id] ?? 0.0)
                    + (float) $item->quantity_used * (int) $line->quantity;
            }
        }

        ksort($requirements, SORT_NUMERIC);
        foreach ($requirements as $itemId => $used) {
            $used = round($used, 4);
            if ($used <= 0) continue;
            $ingredient = InventoryItem::query()
                ->where('restaurant_id', $order->restaurant_id)
                ->lockForUpdate()->findOrFail($itemId);
            if (round((float) $ingredient->quantity, 4) < $used) {
                throw ValidationException::withMessages([
                    'inventory' => "Insufficient inventory for {$ingredient->name}.",
                ]);
            }
            $ingredient->quantity = round((float) $ingredient->quantity - $used, 4);
            $ingredient->save();
            DB::table('restaurant_order_inventory_consumptions')->insert([
                'order_id' => $order->id,
                'inventory_item_id' => $itemId,
                'quantity_consumed' => $used,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function restore(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $entries = DB::table('restaurant_order_inventory_consumptions')
                ->where('order_id', $order->id)
                ->whereNull('restored_at')
                ->orderBy('inventory_item_id')
                ->lockForUpdate()->get();
            foreach ($entries as $entry) {
                $ingredient = InventoryItem::query()->lockForUpdate()->find($entry->inventory_item_id);
                if ($ingredient !== null) {
                    $ingredient->quantity = round((float) $ingredient->quantity + (float) $entry->quantity_consumed, 4);
                    $ingredient->save();
                }
                DB::table('restaurant_order_inventory_consumptions')
                    ->where('id', $entry->id)->whereNull('restored_at')
                    ->update(['restored_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
