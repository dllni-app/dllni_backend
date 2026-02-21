<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmCartData;
use Modules\Supermarket\Models\SmCart;

final class SmCartService
{
    public function store(SmCartData $data): SmCart
    {
        return DB::transaction(static function () use ($data) {
            $cart = SmCart::create($data->onlyModelAttributes());

            return $cart;
        });
    }

    public function update(SmCartData $data, SmCart $cart): SmCart
    {
        return DB::transaction(static function () use ($data, $cart) {
            tap($cart)->update($data->onlyModelAttributes());

            return $cart;
        });
    }
}
