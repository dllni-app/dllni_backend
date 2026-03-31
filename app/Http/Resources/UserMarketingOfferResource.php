<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\MarketingOffer;

/**
 * @mixin MarketingOffer
 */
final class UserMarketingOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'discountLabel' => $this->discount_label,
            'promoCode' => $this->promo_code,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'theme' => $this->theme->value,
            'sortOrder' => $this->sort_order,
            'imageUrl' => $this->getFirstMediaUrl(MarketingOffer::IMAGE_COLLECTION) ?: null,
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
