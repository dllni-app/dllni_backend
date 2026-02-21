<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Models\SmProduct;

final class SmProductService
{
    public function store(SmProductData $data): SmProduct
    {
        return DB::transaction(static function () use ($data) {
            $product = SmProduct::create($data->onlyModelAttributes());

            return $product;
        });
    }

    public function update(SmProductData $data, SmProduct $product): SmProduct
    {
        return DB::transaction(static function () use ($data, $product) {
            tap($product)->update($data->onlyModelAttributes());

            return $product;
        });
    }
}
