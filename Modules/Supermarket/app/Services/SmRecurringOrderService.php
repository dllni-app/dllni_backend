<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmRecurringOrderData;
use Modules\Supermarket\Models\SmRecurringOrder;

final class SmRecurringOrderService
{
    public function store(SmRecurringOrderData $data): SmRecurringOrder
    {
        return DB::transaction(static function () use ($data) {
            $order = SmRecurringOrder::create($data->onlyModelAttributes());

            return $order;
        });
    }

    public function update(SmRecurringOrderData $data, SmRecurringOrder $order): SmRecurringOrder
    {
        return DB::transaction(static function () use ($data, $order) {
            tap($order)->update($data->onlyModelAttributes());

            return $order;
        });
    }
}
