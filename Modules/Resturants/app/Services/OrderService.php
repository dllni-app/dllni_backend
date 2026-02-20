<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\OrderData;
use Modules\Resturants\Models\Order;

final class OrderService
{
    public function store(OrderData $data): Order
    {
        return DB::transaction(static function () use ($data) {
            return Order::create($data->onlyModelAttributes());
        });
    }

    public function update(OrderData $data, Order $order): Order
    {
        return DB::transaction(static function () use ($data, $order) {
            tap($order)->update($data->onlyModelAttributes());

            return $order;
        });
    }
}
