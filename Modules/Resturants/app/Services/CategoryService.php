<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Data\CategoryData;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class CategoryService
{
    public function store(CategoryData $data, array $hierarchy = []): Category
    {
        return DB::transaction(static function () use ($data, $hierarchy) {
            self::lockRestaurant((int) $data->restaurantId);
            $attributes = $data->onlyModelAttributes();
            if (array_key_exists('parentId', $hierarchy)) {
                self::validateParent((int) $data->restaurantId, null, $hierarchy['parentId']);
                $attributes['parent_id'] = $hierarchy['parentId'];
            }
            if (array_key_exists('isActive', $hierarchy)) {
                $attributes['is_active'] = $hierarchy['isActive'];
            }
            $category = Category::create($attributes);
            self::attachMedia($data, $category, false);

            return $category;
        });
    }

    public function update(CategoryData $data, Category $category, array $hierarchy = []): Category
    {
        return DB::transaction(static function () use ($data, $category, $hierarchy) {
            self::lockRestaurant((int) $category->restaurant_id);
            $category = Category::query()->where('restaurant_id', $category->restaurant_id)->lockForUpdate()->findOrFail($category->id);
            $attributes = $data->onlyModelAttributes();
            // The authenticated tenant cannot change through a category update.
            unset($attributes['restaurant_id']);
            if (array_key_exists('parentId', $hierarchy)) {
                self::validateParent((int) $category->restaurant_id, $category, $hierarchy['parentId']);
                $attributes['parent_id'] = $hierarchy['parentId'];
            }
            if (array_key_exists('isActive', $hierarchy)) {
                $attributes['is_active'] = $hierarchy['isActive'];
            }
            $category->update($attributes);
            self::attachMedia($data, $category, true);

            return $category;
        });
    }

    public function delete(Category $category): void
    {
        DB::transaction(static function () use ($category): void {
            self::lockRestaurant((int) $category->restaurant_id);
            $category = Category::query()->where('restaurant_id', $category->restaurant_id)->lockForUpdate()->findOrFail($category->id);
            if ($category->children()->exists() || $category->products()->exists()) {
                throw ValidationException::withMessages(['category' => 'Move products and child sections before deleting this section.']);
            }
            $category->delete();
        });
    }

    /**
     * Replace one complete sibling order atomically. The expected order
     * prevents stale clients from overwriting a concurrent edit.
     */
    public function reorder(int $restaurantId, ?int $parentId, array $expectedIds, array $orderedIds): void
    {
        DB::transaction(static function () use ($restaurantId, $parentId, $expectedIds, $orderedIds): void {
            self::lockRestaurant($restaurantId);
            self::validateParent($restaurantId, null, $parentId);
            $siblings = Category::query()->where('restaurant_id', $restaurantId)
                ->where('parent_id', $parentId)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
            $currentIds = $siblings->pluck('id')->map(fn ($id) => (int) $id)->all();
            $expectedIds = array_map('intval', $expectedIds);
            $orderedIds = array_map('intval', $orderedIds);
            if ($currentIds !== $expectedIds) {
                throw ValidationException::withMessages(['expectedIds' => 'The section order has changed. Refresh before trying again.']);
            }
            $expectedSet = $currentIds;
            $orderedSet = $orderedIds;
            sort($expectedSet);
            sort($orderedSet);
            if ($expectedSet !== $orderedSet) {
                throw ValidationException::withMessages(['orderedIds' => 'Provide every sibling exactly once, without sections from other parents or restaurants.']);
            }
            $byId = $siblings->keyBy('id');
            foreach ($orderedIds as $position => $id) {
                $byId->get($id)->update(['sort_order' => $position]);
            }
        });
    }

    private static function lockRestaurant(int $restaurantId): void
    {
        Restaurant::query()->lockForUpdate()->findOrFail($restaurantId);
    }

    private static function validateParent(int $restaurantId, ?Category $category, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = Category::query()->where('restaurant_id', $restaurantId)->lockForUpdate()->find($parentId);
        if ($parent === null || $parent->parent_id !== null
            || ($category !== null && ($parent->id === $category->id || $category->children()->exists()))) {
            throw ValidationException::withMessages(['parentId' => 'Choose a root section in this restaurant. Only two levels are supported.']);
        }
    }

    private static function attachMedia(CategoryData $data, Category $category, bool $isUpdate): void
    {
        if ($data->categoryImage !== null) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->categoryImage, $category, 'category-image');
            } else {
                MediaHelper::uploadMedia($data->categoryImage, $category, 'category-image');
            }
        }
    }
}
