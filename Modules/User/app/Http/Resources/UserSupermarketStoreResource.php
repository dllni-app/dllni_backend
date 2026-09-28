<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Http\Resources\SmCategoryResource;
use Modules\Supermarket\Http\Resources\SmCouponResource;
use Modules\Supermarket\Http\Resources\SmOfferResource;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Http\Resources\SmStoreHoursResource;
use Modules\Supermarket\Models\SmStore;

/**
 * Public supermarket representation for the customer application.
 *
 * @mixin SmStore
 */
final class UserSupermarketStoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attributes = $this->getAttributes();
        $highestOffer = $this->relationLoaded('highestDiscountOffer') ? $this->highestDiscountOffer : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'score' => array_key_exists('semantic_score', $attributes) && is_numeric($attributes['semantic_score'])
                ? (float) $attributes['semantic_score']
                : null,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'phone' => $this->phone,
            'email' => $this->email,
            'cover' => $this->cover,
            'logo' => $this->logo,
            'averageRating' => $this->average_rating,
            'totalReviews' => $this->total_reviews,
            'isActive' => $this->is_active,
            'isFeatured' => $this->is_featured,
            'isFavorited' => (bool) ($attributes['isFavoritedByUser'] ?? false),
            'cart' => $attributes['cartPayload'] ?? null,
            'distanceKm' => array_key_exists('distanceKm', $attributes)
                ? round((float) $attributes['distanceKm'], 2)
                : null,
            'highestOfferDiscountValue' => $highestOffer?->discount_value !== null
                ? (float) $highestOffer->discount_value
                : null,
            'highestOffer' => $highestOffer !== null ? SmOfferResource::make($highestOffer) : null,
            'storeHours' => SmStoreHoursResource::collection($this->whenLoaded('storeHours')),
            'categories' => SmCategoryResource::collection($this->whenLoaded('categories')),
            'products' => SmProductResource::collection($this->whenLoaded('products')),
            'offers' => SmOfferResource::collection($this->whenLoaded('offers')),
            'coupons' => SmCouponResource::collection($this->whenLoaded('coupons')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
