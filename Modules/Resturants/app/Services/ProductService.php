<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Models\Product;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class ProductService
{
    public function store(ProductData $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create($data->onlyModelAttributes());
            $this->attachMedia($data, $product, false);

            return $product;
        });
    }

    public function update(ProductData $data, Product $product): Product
    {
        return DB::transaction(function () use ($data, $product) {
            tap($product)->update($data->onlyModelAttributes());
            $this->attachMedia($data, $product, true);

            return $product;
        });
    }

    private function attachMedia(ProductData $data, Product $product, bool $isUpdate): void
    {
        if ($data->primaryImage !== null) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->primaryImage, $product, 'primary-image');
            } else {
                MediaHelper::uploadMedia($data->primaryImage, $product, 'primary-image');
            }
        }

        if ($data->images !== null && $data->images !== []) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->images, $product, 'images');
            } else {
                MediaHelper::uploadMedia($data->images, $product, 'images');
            }
        }
    }
}
