<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmOrderData;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderService
{
    public function store(SmOrderData $data): SmOrder
    {
        return DB::transaction(static function () use ($data) {
            $order = SmOrder::create($data->onlyModelAttributes());

            return $order;
        });
    }

    public function update(SmOrderData $data, SmOrder $order): SmOrder
    {
        return DB::transaction(static function () use ($data, $order) {
            tap($order)->update($data->onlyModelAttributes());

            return $order;
        });
    }
}
