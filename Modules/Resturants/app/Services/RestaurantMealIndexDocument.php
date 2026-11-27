<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\Product;

/**
 * Canonical projection for incremental indexing and full catalog sync.
 * Availability is a mutable document field; hidden and modifier-only rows
 * are absent entirely. Ordering and checkout still consult the database.
 */
final class RestaurantMealIndexDocument
{
    public static function query(): Builder
    {
        return Product::query()->where('products.catalog_role', 'independent')
            ->whereHas('restaurant', fn (Builder $q) => $q->where('is_active', true))
            ->whereHas('category', fn (Builder $q) => $q->effectivelyActive()
                ->whereColumn('categories.restaurant_id', 'products.restaurant_id'))
            ->with(['restaurant', 'category', 'foodCategory', 'offers']);
    }

    public static function fields(Product $product): array
    {
        $product->loadMissing(['restaurant', 'category', 'foodCategory', 'offers']);
        $restaurant = $product->restaurant;
        $family = $product->classification_status === 'reviewed' && $product->foodCategory?->is_active
            ? $product->foodCategory : null;
        $tags = array_values(array_unique(array_filter(array_merge(
            $product->search_tags ?? [],
            $family?->aliases ?? [],
            [$family?->name_ar, $family?->name_en, $family?->slug, $product->product_kind,
                $product->servings ? 'servings:'.$product->servings : null]
        ))));

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'description' => (string) $product->description,
            'item_type' => $product->item_type,
            'search_tags' => $tags,
            'price' => (float) $product->price,
            'discounted_price' => $product->effectiveDiscountedPrice(),
            'preparation_time' => $product->preparation_time,
            'is_available' => $product->isAvailableNow() && $product->stock_quantity > 0
                && !$restaurant->is_temporarily_closed
                && ($restaurant->suspension_until === null || $restaurant->suspension_until <= now()),
            'is_featured' => (bool) $product->is_featured,
            'restaurant_id' => $restaurant->id,
            'restaurant_name' => $restaurant->name,
            'restaurant_preparation_min' => $restaurant->estimated_preparation_time_min,
            'restaurant_preparation_max' => $restaurant->estimated_preparation_time_max ?? $restaurant->estimated_preparation_time,
            'restaurant_rating' => (float) $restaurant->average_rating,
            'restaurant_reputation' => (float) $restaurant->reputation_score,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
        ];
    }
}
