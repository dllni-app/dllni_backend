<?php

declare(strict_types=1);

namespace Modules\Resturants\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Jobs\SyncRestaurantMealIndex;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

/**
 * Product documents include fields owned by these related models. Refresh
 * affected IDs after commit, including children of a changed root section.
 */
final class MealIndexDependencyObserver
{
    /** @var \WeakMap<Model, list<int>>|null */
    private static ?\WeakMap $deletingIds = null;

    public function updated(Model $model): void
    {
        $fields = match (true) {
            $model instanceof Category => ['name', 'parent_id', 'is_active'],
            $model instanceof FoodDiscoveryCategory => ['name_ar', 'name_en', 'slug', 'aliases', 'is_active'],
            $model instanceof Restaurant => ['name', 'is_active', 'is_temporarily_closed', 'suspension_until',
                'estimated_preparation_time_min', 'estimated_preparation_time_max', 'average_rating', 'reputation_score'],
            default => [],
        };
        if ($fields !== [] && $model->wasChanged($fields)) {
            $this->refreshProducts($model);
        }
    }

    public function deleting(Model $model): void
    {
        // Capture IDs before cascading deletes or SET NULL destroys the relation.
        if (!config('services.dallelni_search.meal_index_enabled')) return;
        self::$deletingIds ??= new \WeakMap();
        self::$deletingIds[$model] = $this->affectedProducts($model)->pluck('products.id')->map(fn ($id) => (int) $id)->all();
    }

    public function deleted(Model $model): void
    {
        if (self::$deletingIds === null || !isset(self::$deletingIds[$model])) return;
        $ids = self::$deletingIds[$model];
        unset(self::$deletingIds[$model]);
        foreach ($ids as $id) {
            SyncRestaurantMealIndex::dispatch($id)->afterCommit();
        }
    }

    private function refreshProducts(Model $model): void
    {
        if (!config('services.dallelni_search.meal_index_enabled')) return;
        $this->affectedProducts($model)->chunkById(200, function ($rows): void {
            foreach ($rows as $product) {
                SyncRestaurantMealIndex::dispatch((int) $product->id)->afterCommit();
            }
        }, 'products.id', 'id');
    }

    private function affectedProducts(Model $model): \Illuminate\Database\Eloquent\Builder
    {
        $products = Product::query()->select('products.id');
        if ($model instanceof Category) {
            $products->where('restaurant_id', $model->restaurant_id)
                ->where(function ($query) use ($model): void {
                    $query->where('category_id', $model->id)
                        ->orWhereIn('category_id', Category::query()->select('id')
                            ->where('parent_id', $model->id)->where('restaurant_id', $model->restaurant_id));
                });
        } elseif ($model instanceof FoodDiscoveryCategory) {
            $products->where('primary_food_category_id', $model->id);
        } elseif ($model instanceof Restaurant) {
            $products->where('restaurant_id', $model->id);
        } else {
            $products->whereRaw('1 = 0');
        }
        return $products;
    }
}
