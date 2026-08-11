<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\InventoryItemData;
use Modules\Resturants\Models\InventoryItem;

final class InventoryItemService
{
    public function store(InventoryItemData $data): InventoryItem
    {
        return DB::transaction(static function () use ($data) {
            $item = InventoryItem::create($data->onlyModelAttributes());

            $sync = self::buildProductSync($data);
            if ($sync !== null) {
                $item->products()->sync($sync);
            }

            return $item;
        });
    }

    public function update(InventoryItemData $data, InventoryItem $item): InventoryItem
    {
        return DB::transaction(static function () use ($data, $item) {
            tap($item)->update($data->onlyModelAttributes());

            $sync = self::buildProductSync($data);
            if ($sync !== null) {
                $item->products()->sync($sync);
            }

            return $item;
        });
    }

    /**
     * Build the product pivot payload while keeping older productIds-only
     * clients compatible. A provided `products` array is authoritative and
     * carries the amount of this inventory item consumed by one ordered unit
     * of the linked product.
     *
     * @return array<int, array{quantity_used: float}>|null
     */
    private static function buildProductSync(InventoryItemData $data): ?array
    {
        if ($data->products !== null) {
            $sync = [];

            foreach ($data->products as $product) {
                $productId = (int) $product['productId'];
                $sync[$productId] = [
                    'quantity_used' => (float) $product['quantityUsed'],
                ];
            }

            return $sync;
        }

        if ($data->productIds === null) {
            return null;
        }

        $sync = [];
        foreach ($data->productIds as $productId) {
            $sync[(int) $productId] = ['quantity_used' => 1.0];
        }

        return $sync;
    }
}
