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

        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'categoryId' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price !== null ? (float) $this->price : null,
            'discountType' => $this->discount_type,
            'discountValue' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'discountedPrice' => $this->discounted_price !== null ? (float) $this->discounted_price : null,
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
}
