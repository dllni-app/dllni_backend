<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use Modules\Resturants\Models\Product;
use Modules\User\Services\RestaurantPopularMealRanker;
use PHPUnit\Framework\TestCase;

final class RestaurantPopularMealRankerTest extends TestCase
{
    public function test_popularity_uses_actual_completed_units_with_merchant_and_food_diversity(): void
    {
        $products = collect();
        foreach (range(1, 24) as $id) {
            $product = new Product;
            $product->setRawAttributes([
                'id' => $id,
                'restaurant_id' => $id <= 8 ? 1 : ($id <= 16 ? 2 : 3),
                'primary_food_category_id' => ($id % 3) + 1,
                'completed_units_30d' => 100 - $id,
            ]);
            $products->push($product);
        }

        $selected = RestaurantPopularMealRanker::diversify($products);
        self::assertNotEmpty($selected);
        self::assertSame(1, $selected->first()->id);

        foreach ($selected->values() as $index => $product) {
            $window = $selected->slice(max(0, $index - 7), min(8, $index + 1))->values();
            self::assertLessThanOrEqual(2, $window->filter(
                fn (Product $p): bool => $p->restaurant_id === $product->restaurant_id
            )->count());
            self::assertLessThanOrEqual(3, $window->filter(
                fn (Product $p): bool => $p->primary_food_category_id === $product->primary_food_category_id
            )->count());
        }

        self::assertSame(
            $selected->pluck('id')->all(),
            RestaurantPopularMealRanker::diversify($products)->pluck('id')->all()
        );
    }
}
