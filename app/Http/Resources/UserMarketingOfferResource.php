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
     * Homepage banners are image-only in the user application.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'imageUrl' => $this->getFirstMediaUrl(MarketingOffer::IMAGE_COLLECTION) ?: null,
        ];
    }
}
