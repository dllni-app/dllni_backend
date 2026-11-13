<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Resturants\Models\Product;

/**
 * Single customer-facing eligibility policy for Restaurant V2 discovery.
 * A meal is not just any available Product: toppings and ingredients must be
 * exposed through ModifierGroup/Modifier, not standalone food suggestions.
 */
final class RestaurantFoodEligibility
{
    public static function browsableProducts(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->where('products.catalog_role', 'independent')
            ->where(function (Builder $availability): void {
                $availability->where('products.is_available', true)
                    ->orWhere(function (Builder $expired): void {
                        $expired->where('products.is_available', false)
                            ->whereNotNull('products.unavailable_until')
                            ->where('products.unavailable_until', '<', now());
                    });
            });
    }

    public static function curatedMeals(Builder|Relation $query): Builder|Relation
    {
        return self::browsableProducts($query)
            ->where('products.classification_status', 'reviewed')
            ->whereIn('products.product_kind', ['main_dish', 'combo_meal'])
            ->whereNotNull('products.primary_food_category_id');
    }

    public static function isIndependentlyOrderable(Product $product): bool
    {
        return ($product->catalog_role ?? 'independent') === 'independent';
    }
}
