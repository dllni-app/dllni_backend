<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmStore;

/**
 * @mixin SmStore
 */
final class SmStoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->getAttributes();
        $highestOffer = $this->relationLoaded('highestDiscountOffer') ? $this->highestDiscountOffer : null;

        return [
            'id' => $this->id,
            'ownerUserId' => $this->owner_user_id,
            'owner' => UserResource::make($this->whenLoaded('owner')),
            'name' => $this->name,
            'slug' => $this->slug,
            'score' => array_key_exists('semantic_score', $attributes)
                ? (is_numeric($attributes['semantic_score']) ? (float) $attributes['semantic_score'] : null)
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
            'trustScore' => $this->trust_score,
            'warningCount' => $this->warning_count,
            'isActive' => $this->is_active,
            'isFeatured' => $this->is_featured,
            'isFavorited' => (bool) ($attributes['isFavoritedByUser'] ?? false),
            'cart' => $attributes['cartPayload'] ?? null,
            'suspensionUntil' => $this->suspension_until?->toDateTimeString(),
            'distanceKm' => array_key_exists('distanceKm', $this->getAttributes())
                ? round((float) $this->distanceKm, 2)
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
            'orders' => SmOrderResource::collection($this->whenLoaded('orders')),
            'documents' => SmStoreDocumentResource::collection($this->whenLoaded('documents')),
            'trustLogs' => SmStoreTrustLogResource::collection($this->whenLoaded('trustLogs')),
            'dailyStats' => SmStoreDailyStatResource::collection($this->whenLoaded('dailyStats')),
            'commissionRules' => SmCommissionRuleResource::collection($this->whenLoaded('commissionRules')),
            'assistantQueries' => SmAssistantQueryResource::collection($this->whenLoaded('assistantQueries')),
            'recurringOrders' => SmRecurringOrderResource::collection($this->whenLoaded('recurringOrders')),
            'staff' => SmStoreStaffResource::collection($this->whenLoaded('staff')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
