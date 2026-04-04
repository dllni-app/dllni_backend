<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Offer;

/**
 * @mixin Offer
 */
final class UserRestaurantProductOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'discountType' => $this->discount_type?->value ?? $this->discount_type,
            'discountValue' => $this->discount_value ? (float) $this->discount_value : null,
            'badgeText' => $this->listingBadgeText(),
            'startsAt' => $this->starts_at?->toDateTimeString(),
            'endsAt' => $this->ends_at?->toDateTimeString(),
            'urgencyTag' => $this->listingUrgencyTag()?->value,
        ];
    }
}
