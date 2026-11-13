<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Collection;
use Modules\Resturants\Models\Product;

/**
 * Stable, bounded diversification of an already popularity-ranked candidate set.
 *
 * No invented sales: caller must supply the completed_units_30d aggregate.
 * Do not let a single merchant or food type dominate eight visible cards.
 */
final class RestaurantPopularMealRanker
{
    /**
     * @param Collection<int, Product> $candidates
     * @return Collection<int, Product>
     */
    public static function diversify(Collection $candidates, int $maxItems = 120): Collection
    {
        $remaining = $candidates->sort(function (Product $a, Product $b): int {
            return ((int) ($b->completed_units_30d ?? 0) <=> (int) ($a->completed_units_30d ?? 0))
                ?: ((int) $a->id <=> (int) $b->id);
        })->values();

        $result = collect();

        while ($remaining->isNotEmpty() && $result->count() < $maxItems) {
            $lastSeven = $result->slice(-7);
            $selected = $remaining->search(function (Product $candidate) use ($lastSeven): bool {
                $sameMerchant = $lastSeven->filter(
                    fn (Product $product): bool => (int) $product->restaurant_id === (int) $candidate->restaurant_id
                )->count();
                $sameFoodType = $lastSeven->filter(
                    fn (Product $product): bool => (int) $product->primary_food_category_id === (int) $candidate->primary_food_category_id
                )->count();

                return $sameMerchant < 2 && $sameFoodType < 3;
            });

            if ($selected === false) {
                break; // Honest shorter feed beats repeated near-duplicates.
            }

            $result->push($remaining->get($selected));
            $remaining = $remaining->forget($selected)->values();
        }

        return $result;
    }
}
