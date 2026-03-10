<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\MediaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmProduct;

/**
 * @mixin SmProduct
 */
final class SmProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'categoryId' => $this->category_id,
            'category' => SmCategoryResource::make($this->whenLoaded('category')),
            'masterProductId' => $this->master_product_id,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'sourceType' => $this->source_type?->value,
            'description' => $this->description,
            'price' => $this->price,
            'discountedPrice' => $this->discounted_price,
            'image' => MediaResource::make($this->whenLoaded('media', fn () => $this->getFirstMedia(SmProduct::IMAGE_COLLECTION))),
            'imageUrl' => $this->whenLoaded('media', fn () => $this->getFirstMediaUrl(SmProduct::IMAGE_COLLECTION) ?: null),
            'stockQuantity' => $this->stock_quantity,
            'lowStockThreshold' => $this->low_stock_threshold,
            'expiresAt' => $this->expires_at?->toDateTimeString(),
            'isAvailable' => $this->is_available,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
