<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmOfferProduct;
use Modules\Supermarket\Models\SmProduct;

/**
 * Customer-safe supermarket offer details. This intentionally does not reuse
 * the owner resource because offer analytics and owner-only fields must not
 * cross the public user boundary.
 *
 * @mixin SmOffer
 */
final class UserSupermarketOfferDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SmOffer $offer */
        $offer = $this->resource;
        $store = $offer->relationLoaded('store') ? $offer->store : null;
        $storeAttrs = $store?->getAttributes() ?? [];

        return [
            'id' => $offer->id,
            'storeId' => $offer->store_id,
            'store' => $store === null ? null : [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'address' => $store->address,
                'city' => $store->city,
                'neighborhood' => $store->neighborhood,
                'cover' => $storeAttrs['cover'] ?? null,
                'logo' => $storeAttrs['logo'] ?? null,
                'averageRating' => $store->average_rating,
                'totalReviews' => $store->total_reviews,
                'isActive' => $store->is_active,
                'isOpenNow' => $store->currentlyOpen(),
            ],
            'name' => $offer->name,
            'description' => $offer->description,
            'offerType' => $offer->offer_type,
            'discountValue' => $offer->discount_value,
            'discountPercent' => $offer->discount_percent,
            'imageUrl' => ($storeAttrs['cover'] ?? null) ?? ($storeAttrs['logo'] ?? null),
            'startsAt' => $offer->starts_at?->toDateTimeString(),
            'endsAt' => $offer->ends_at?->toDateTimeString(),
            'isActive' => $offer->is_active,
            'offerProducts' => $offer->relationLoaded('offerProducts')
                ? $offer->offerProducts->map(static fn (SmOfferProduct $item): array => [
                    'id' => $item->id,
                    'offerId' => $item->offer_id,
                    'productId' => $item->product_id,
                    'offerPrice' => $item->offer_price,
                    'maxQuantity' => $item->max_quantity,
                    'product' => self::product($item->product),
                ])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function product(?SmProduct $product): ?array
    {
        if ($product === null) return null;

        $imageUrl = $product->relationLoaded('media')
            ? ($product->getFirstMediaUrl(SmProduct::IMAGE_COLLECTION) ?: null)
            : null;
        if ($imageUrl === null && $product->relationLoaded('masterProduct') && $product->masterProduct !== null) {
            $imageUrl = $product->masterProduct->getFirstMediaUrl() ?: null;
        }

        return [
            'id' => $product->id,
            'storeId' => $product->store_id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price,
            'discountedPrice' => $product->discounted_price,
            'finalPrice' => $product->effectivePrice(),
            'imageUrl' => $imageUrl,
            'stockQuantity' => $product->stock_quantity,
            'isAvailable' => $product->is_available,
            'expiresAt' => $product->expires_at?->toDateTimeString(),
        ];
    }
}
