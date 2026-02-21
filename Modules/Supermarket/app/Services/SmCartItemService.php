<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmCartItemData;
use Modules\Supermarket\Models\SmCartItem;

final class SmCartItemService
{
    public function store(SmCartItemData $data): SmCartItem
    {
        return DB::transaction(static function () use ($data) {
            $item = SmCartItem::create($data->onlyModelAttributes());

            return $item;
        });
    }

    public function update(SmCartItemData $data, SmCartItem $item): SmCartItem
    {
        return DB::transaction(static function () use ($data, $item) {
            tap($item)->update($data->onlyModelAttributes());

            return $item;
        });
    }
}
