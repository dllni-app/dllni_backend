<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\MediaResource;
use App\Models\MasterProduct;
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
        $attributes = $this->getAttributes();
        $hasDiscount = $this->discounted_price !== null;
        $finalPrice = $this->discounted_price ?? $this->price;
        $originalPrice = $hasDiscount ? $this->price : null;

        $storePrimaryMedia = $this->relationLoaded('media')
            ? $this->getFirstMedia(SmProduct::IMAGE_COLLECTION)
            : null;
        $storePrimaryImageUrl = $this->relationLoaded('media')
            ? ($this->getFirstMediaUrl(SmProduct::IMAGE_COLLECTION) ?: null)
            : null;

        $masterFallbackMedia = null;
        $masterFallbackImageUrl = null;

        if ($storePrimaryMedia === null && $this->relationLoaded('masterProduct') && $this->masterProduct !== null && $this->masterProduct->relationLoaded('media')) {
            $masterFallbackMedia = $this->masterProduct->getFirstMedia(MasterProduct::IMAGE_COLLECTION)
                ?? $this->masterProduct->getFirstMedia();
            $masterFallbackImageUrl = $masterFallbackMedia?->getFullUrl();
        }

        $primaryMedia = $storePrimaryMedia ?? $masterFallbackMedia;
        $primaryImageUrl = $storePrimaryImageUrl ?? $masterFallbackImageUrl;

        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'categoryId' => $this->category_id,
            'category' => SmCategoryResource::make($this->whenLoaded('category')),
            'masterProductId' => $this->master_product_id,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'score' => array_key_exists('semantic_score', $attributes)
                ? (is_numeric($attributes['semantic_score']) ? (float) $attributes['semantic_score'] : null)
                : null,
            'sourceType' => $this->source_type?->value,
            'description' => $this->description,
            'price' => $this->price,
            'discountedPrice' => $this->discounted_price,
            'finalPrice' => $finalPrice,
            'originalPrice' => $originalPrice,
            'hasDiscount' => $hasDiscount,
            'isFavorite' => (bool) ($attributes['isFavoritedByUser'] ?? false),
            'offers' => $this->whenLoaded('offerProducts', function () {
                return SmOfferResource::collection(
                    $this->offerProducts->map(fn ($offerProduct) => $offerProduct->offer)->filter()
                );
            }),
            'options' => $this->whenLoaded('modifierGroups', fn () => $this->modifierGroups
                ->values()
                ->map(fn ($group): array => [
                    'id' => $group->id,
                    'storeId' => $group->store_id,
                    'name' => $group->name,
                    'isRequired' => (bool) $group->is_required,
                    'minSelections' => (int) $group->min_selections,
                    'maxSelections' => (int) $group->max_selections,
                    'sortOrder' => (int) $group->sort_order,
                    'modifiers' => $group->modifiers
                        ->values()
                        ->map(fn ($modifier): array => [
                            'id' => $modifier->id,
                            'modifierGroupId' => $modifier->modifier_group_id,
                            'name' => $modifier->name,
                            'price' => (float) $modifier->price,
                            'sortOrder' => (int) $modifier->sort_order,
                            'isAvailable' => (bool) $modifier->is_available,
                        ])
                        ->all(),
                ])
                ->all()),
            'image' => $primaryMedia !== null ? MediaResource::make($primaryMedia) : null,
            'imageUrl' => $primaryImageUrl,
            'primaryImage' => $primaryImageUrl,
            'images' => $this->whenLoaded('media', fn () => MediaResource::collection($this->getMedia(SmProduct::IMAGE_COLLECTION))),
            'imageUrls' => $this->whenLoaded('media', fn () => $this->getMedia(SmProduct::IMAGE_COLLECTION)
                ->map(fn ($media): string => $media->getFullUrl())
                ->values()
                ->all()),
            'stockQuantity' => $this->stock_quantity,
            'lowStockThreshold' => $this->low_stock_threshold,
            'expiresAt' => $this->expires_at?->toDateTimeString(),
            'isAvailable' => $this->is_available,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
