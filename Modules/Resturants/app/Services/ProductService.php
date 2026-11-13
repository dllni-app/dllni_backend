<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Models\Product;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class ProductService
{
    public function __construct(private ActivityLogService $activityLogService) {}

    public function store(ProductData $data): Product
    {
        return DB::transaction(function () use ($data) {
            $attributes = $data->onlyModelAttributes();
            if ($data->productKind === null) {
                unset($attributes['product_kind']);
            }
            if ($data->primaryFoodCategoryId === null) {
                unset($attributes['primary_food_category_id']);
            }
            if ($data->servings !== null) $attributes['servings'] = $data->servings ?: null;
            $product = Product::create($attributes);
            $this->attachMedia($data, $product, false);
            $this->activityLogService->logProductCreated($product, (int) $product->restaurant_id);

            return $product;
        });
    }

    public function update(ProductData $data, Product $product): Product
    {
        return DB::transaction(function () use ($data, $product) {
            $oldAttributes = $product->getAttributes();
            $attributes = $data->onlyModelAttributes();
            if ($data->productKind === null) {
                unset($attributes['product_kind']);
            } elseif ($data->productKind !== $product->product_kind) {
                // Owner edits invalidate an earlier editorial classification approval.
                $attributes['classification_status'] = 'unreviewed';
                $attributes['classified_at'] = null;
            }
            if ($data->primaryFoodCategoryId === null) {
                unset($attributes['primary_food_category_id']);
            } elseif ($data->primaryFoodCategoryId !== $product->primary_food_category_id) {
                $attributes['classification_status'] = 'unreviewed';
                $attributes['classified_at'] = null;
            }
            if ($data->servings === null) {
                unset($attributes['servings']);
            } else {
                $attributes['servings'] = $data->servings ?: null;
            }
            if ($data->ingredientExclusions === null) unset($attributes['ingredient_exclusions']);
            // Ingredient claims must be explicitly reviewed after owner edits.
            if ($data->ingredientExclusions !== null && $data->ingredientExclusions !== ($product->ingredient_exclusions ?? [])) {
                $attributes['classification_status'] = 'unreviewed';
                $attributes['classified_at'] = null;
            }
            tap($product)->update($attributes);
            $this->attachMedia($data, $product, true);
            $this->activityLogService->logProductUpdated($product, (int) $product->restaurant_id, $oldAttributes);

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
