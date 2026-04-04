<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;

/**
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->getFirstMediaUrl('category-image') ?: null;

        // Fallback to first product image if category image not available
        if ($imageUrl === null && $this->relationLoaded('products')) {
            $firstProduct = $this->products->first();
            if ($firstProduct instanceof Product) {
                $imageUrl = $firstProduct->getFirstMediaUrl('primary-image') ?: null;
            }
        }

        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sortOrder' => $this->sort_order,
            'imageUrl' => $imageUrl,
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'products' => $this->whenLoaded('products', fn () => ProductResource::collection($this->products)),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
