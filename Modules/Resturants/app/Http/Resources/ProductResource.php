<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Product;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attrs = $this->getAttributes();
        $originalPrice = $this->price !== null ? (float) $this->price : null;
        $effectiveDiscountedPrice = $originalPrice !== null ? $this->effectiveDiscountedPrice() : null;

        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'categoryId' => $this->category_id,
            'itemType' => $this->item_type,
            'name' => $this->name,
            'description' => $this->description,
            'searchTags' => $this->search_tags ?? [],
            'price' => $originalPrice,
            'discountedPrice' => $effectiveDiscountedPrice,
            'displayPrice' => $originalPrice !== null ? $this->effectivePrice() : null,
            'originalPrice' => $effectiveDiscountedPrice !== null ? $originalPrice : null,
            'isFavorite' => (bool) ($attrs['isFavoritedByUser'] ?? false),
            'cartQuantity' => (int) ($attrs['cartQuantity'] ?? 0),
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
                'preparationTimeMin' => $this->restaurant->estimated_preparation_time_min,
                'preparationTimeMax' => $this->restaurant->estimated_preparation_time_max ?? $this->restaurant->estimated_preparation_time,
                'averageRating' => (float) ($this->restaurant->average_rating ?? 0),
                'reputationScore' => (float) ($this->restaurant->reputation_score ?? 0),
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
}
