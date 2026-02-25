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

            if (! empty($data->productIds)) {
                $sync = [];
                foreach ($data->productIds as $productId) {
                    $sync[$productId] = ['quantity_used' => 1];
                }
                $item->products()->sync($sync);
            }

            return $item;
        });
    }

    public function update(InventoryItemData $data, InventoryItem $item): InventoryItem
    {
        return DB::transaction(static function () use ($data, $item) {
            tap($item)->update($data->onlyModelAttributes());

            if ($data->productIds !== null) {
                $sync = [];
                foreach ($data->productIds as $productId) {
                    $sync[$productId] = ['quantity_used' => 1];
                }
                $item->products()->sync($sync);
            }

            return $item;
        });
    }
}
