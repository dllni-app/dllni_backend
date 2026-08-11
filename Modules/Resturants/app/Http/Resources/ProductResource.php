<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Product;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attrs = $this->getAttributes();
        $price = $this->price !== null ? (float) $this->price : null;
        $discountedPrice = $this->resolveDiscountedPrice($price);

        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'categoryId' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $price,
            'discountedPrice' => $discountedPrice,
            'isFavorite' => (bool) ($attrs['isFavoritedByUser'] ?? false),
            'isAvailable' => $this->is_available,
            'isAvailableNow' => $this->isAvailableNow(),
            'availabilityMode' => $this->availabilityMode(),
            'unavailableUntil' => $this->unavailable_until?->toDateTimeString(),
            'availabilityNote' => $this->availability_note,
            'stockQuantity' => $this->stock_quantity,
            'lowStockThreshold' => $this->low_stock_threshold,
            'preparationTime' => $this->preparation_time,
            'isFeatured' => $this->is_featured,
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'modifierGroups' => $this->whenLoaded('modifierGroups'),
            'substitutions' => $this->whenLoaded('substitutions'),
            'primaryImage' => $this->getFirstMediaUrl('primary-image'),
            'images' => $this->getMedia('images')->map(fn ($media) => $media->getUrl())->values()->all(),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }

    private function resolveDiscountedPrice(?float $price): ?float
    {
        if ($price === null) {
            return null;
        }

        $storedDiscountedPrice = $this->discounted_price !== null
            ? (float) $this->discounted_price
            : null;

        if ($storedDiscountedPrice !== null && $storedDiscountedPrice < $price) {
            return $storedDiscountedPrice;
        }

        if (! $this->relationLoaded('offers')) {
            return null;
        }

        $offer = $this->offers
            ->first(fn ($offer) => $offer->is_active && (
                $offer->ends_at === null || $offer->ends_at->isFuture()
            ));

        if ($offer === null || $offer->discount_value === null) {
            return null;
        }

        $discountValue = (float) $offer->discount_value;
        if ($discountValue <= 0) {
            return null;
        }

        $discountedPrice = match ($offer->discount_type) {
            DiscountType::Percentage => $price * (1 - (min($discountValue, 100) / 100)),
            DiscountType::FixedAmount => $price - $discountValue,
            default => $price,
        };

        $discountedPrice = max(0, round($discountedPrice, 2));

        return $discountedPrice < $price ? $discountedPrice : null;
    }
}
