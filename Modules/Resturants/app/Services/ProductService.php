<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Models\Product;

final class ProductService
{
    public function store(ProductData $data): Product
    {
        return DB::transaction(static function () use ($data) {
            return Product::create($data->onlyModelAttributes());
        });
    }

    public function update(ProductData $data, Product $product): Product
    {
        return DB::transaction(static function () use ($data, $product) {
            tap($product)->update($data->onlyModelAttributes());

            return $product;
        });
    }
}
