<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Product;

/** @mixin Product */
final class RestaurantV2ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $thumbnail = (bool) $request->attributes->get('restaurant_home_thumbnail', false);
        $price = $product->price !== null ? (float) $product->price : null;
        $displayPrice = $price !== null ? $product->effectivePrice() : null;
        $discounted = $price !== null && $displayPrice !== null && $displayPrice < $price;
        $restaurant = $product->getRelationValue('restaurant');
        $category = $product->getRelationValue('category');
        $foodCategory = $product->relationLoaded('foodCategory')
            ? $product->getRelation('foodCategory')
            : null;

        return [
            'id' => (int) $product->id,
            'restaurantId' => (int) $product->restaurant_id,
            'restaurant' => $restaurant !== null ? [
                'id' => (int) $restaurant->id,
                'name' => $restaurant->name,
                'imageUrl' => \App\Support\ImageVariants::url($restaurant, 'primary-image', true),
            ] : null,
            'categoryId' => $product->category_id !== null ? (int) $product->category_id : null,
            'category' => $category !== null ? ['id' => (int) $category->id, 'name' => $category->name] : null,
            'productKind' => $product->product_kind,
            'servings' => $product->servings,
            'isIndependentlyOrderable' => $product->isIndependentlyOrderable(),
            'foodCategory' => $foodCategory !== null ? [
                'id' => (int) $foodCategory->id,
                'slug' => $foodCategory->slug,
                'name' => $foodCategory->localizedName((string) app()->getLocale()),
            ] : null,
            'name' => $product->name,
            'description' => $product->description,
            'imageUrl' => \App\Support\ImageVariants::url($product, 'primary-image', $thumbnail),
            'images' => $product->getMedia('images')->map(fn ($media): string => \App\Support\ImageVariants::mediaUrl($media, $thumbnail))->values()->all(),
            'price' => $displayPrice !== null ? number_format($displayPrice, 2, '.', '') : null,
            'originalPrice' => $discounted ? number_format($price, 2, '.', '') : null,
            'currency' => config('app.currency', 'SYP'),
            'distanceKm' => array_key_exists('distanceKm', $product->getAttributes()) && $product->distanceKm !== null
                ? round((float) $product->distanceKm, 2)
                : null,
            'promotions' => \Modules\User\Services\RestaurantPromotionCatalog::forProduct($product),
            'isAvailable' => (bool) $product->isAvailableNow(),
            'availabilityMode' => $product->availabilityMode(),
            'unavailableUntil' => $product->unavailable_until?->toIso8601String(),
            'availabilityNote' => $product->availability_note,
            'isFeatured' => (bool) $product->is_featured,
            'isFavoritedByUser' => (bool) ($product->getAttribute('isFavoritedByUser') ?? false),
        ];
    }
}