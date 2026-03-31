<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Product;

/**
 * @mixin Product
 */
final class UserRestaurantSuggestedProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        $restaurant = $product->restaurant;

        $price = $product->price !== null ? (float) $product->price : null;
        $discounted = $product->discounted_price !== null ? (float) $product->discounted_price : null;
        $hasDiscount = $price !== null && $discounted !== null && $discounted < $price;
        $displayPrice = $hasDiscount ? $discounted : $price;

        $tags = [];
        $seenSlugs = [];

        if ($product->relationLoaded('category') && $product->category !== null) {
            $slug = $product->category->slug;
            if (! isset($seenSlugs[$slug])) {
                $seenSlugs[$slug] = true;
                $tags[] = [
                    'name' => $product->category->name,
                    'slug' => $slug,
                    'kind' => 'menu_category',
                ];
            }
        }

        if ($restaurant->relationLoaded('cuisineTypes')) {
            foreach ($restaurant->cuisineTypes as $cuisineType) {
                $slug = $cuisineType->slug;
                if (isset($seenSlugs[$slug])) {
                    continue;
                }
                $seenSlugs[$slug] = true;
                $tags[] = [
                    'name' => $cuisineType->name,
                    'slug' => $slug,
                    'kind' => 'cuisine',
                ];
            }
        }

        return [
            'productId' => $product->id,
            'name' => $product->name,
            'rating' => $restaurant->average_rating !== null
                ? round((float) $restaurant->average_rating, 1)
                : null,
            'displayPrice' => $displayPrice,
            'originalPrice' => $hasDiscount ? $price : null,
            'currency' => config('app.currency', 'IQD'),
            'primaryImageUrl' => $product->getFirstMediaUrl('primary-image') ?: null,
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'district' => $restaurant->district,
                'city' => $restaurant->city,
            ],
            'tags' => $tags,
        ];
    }
}
