<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Models\SmProduct;

final class SmProductService
{
    public function store(SmProductData $data, ?UploadedFile $image = null): SmProduct
    {
        return DB::transaction(static function () use ($data, $image) {
            $product = SmProduct::create($data->onlyModelAttributes());

            if ($image !== null) {
                $product->addMedia($image)->toMediaCollection(SmProduct::IMAGE_COLLECTION);
            }

            return $product;
        });
    }

    public function update(SmProductData $data, SmProduct $product, ?UploadedFile $image = null): SmProduct
    {
        return DB::transaction(static function () use ($data, $product, $image) {
            tap($product)->update($data->onlyModelAttributes());

            if ($image !== null) {
                $product->addMedia($image)->toMediaCollection(SmProduct::IMAGE_COLLECTION);
            }

            return $product;
        });
    }
}
