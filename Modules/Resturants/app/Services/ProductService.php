<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;
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
            $this->lockCategory((int) $data->restaurantId, (int) $data->categoryId);
            ProductClassificationRules::validate($attributes);
            $product = Product::create($attributes);
            $this->attachMedia($data, $product, false);
            $this->activityLogService->logProductCreated($product, (int) $product->restaurant_id);

            return $product;
        });
    }

    public function update(ProductData $data, Product $product, ?array $provided = null): Product
    {
        return DB::transaction(function () use ($data, $product, $provided) {
            $this->lockCategory((int) $product->restaurant_id, (int) $data->categoryId);
            $product = Product::query()->where('restaurant_id', $product->restaurant_id)->lockForUpdate()->findOrFail($product->id);
            $oldAttributes = $product->getAttributes();
            $attributes = $data->onlyModelAttributes();
            unset($attributes['restaurant_id'], $attributes['classification_status'], $attributes['classified_at'], $attributes['catalog_role']);
            $semanticFields = [
                'itemType' => 'item_type', 'productKind' => 'product_kind',
                'primaryFoodCategoryId' => 'primary_food_category_id', 'servings' => 'servings',
                'includedComponents' => 'included_components', 'ingredientExclusions' => 'ingredient_exclusions',
            ];
            foreach ($semanticFields as $apiField => $dbField) {
                if ($provided !== null && array_key_exists($apiField, $provided)) {
                    $attributes[$dbField] = $data->{$apiField};
                } elseif ($provided !== null || $data->{$apiField} === null) {
                    unset($attributes[$dbField]);
                }
            }
            $product->fill($attributes);
            if ($product->isDirty([...array_values($semanticFields), 'name', 'description'])) {
                ProductClassificationRules::validate($product->attributesToArray());
                $attributes['classification_status'] = 'unreviewed';
                $attributes['classified_at'] = null;
            }
            tap($product)->update($attributes);
            $this->attachMedia($data, $product, true);
            $this->activityLogService->logProductUpdated($product, (int) $product->restaurant_id, $oldAttributes);

            return $product;
        });
    }

    private function lockCategory(int $restaurantId, int $categoryId): void
    {
        Restaurant::query()->lockForUpdate()->findOrFail($restaurantId);
        if (Category::query()->where('restaurant_id', $restaurantId)->lockForUpdate()->find($categoryId) === null) {
            throw ValidationException::withMessages(['categoryId' => 'Choose a menu section from your restaurant.']);
        }
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
