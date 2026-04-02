<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOffer;

/**
 * @mixin SmOffer
 */
final class UserSupermarketHomeOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SmOffer $offer */
        $offer = $this->resource;
        $store = $offer->relationLoaded('store') ? $offer->store : null;
        $storeAttrs = $store !== null ? $store->getAttributes() : [];

        return [
            'id' => $offer->id,
            'storeId' => $offer->store_id,
            'store' => $store === null ? null : [
                'id' => $store->id,
                'name' => $store->name,
                'cover' => $storeAttrs['cover'] ?? null,
                'logo' => $storeAttrs['logo'] ?? null,
            ],
            'name' => $offer->name,
            'description' => $offer->description,
            'offerType' => $offer->offer_type,
            'discountValue' => $offer->discount_value,
            'discountPercent' => $offer->discount_percent,
            'badgeText' => $this->formatOfferBadge($offer),
            'imageUrl' => ($storeAttrs['cover'] ?? null) ?? ($storeAttrs['logo'] ?? null),
            'startsAt' => $offer->starts_at?->toDateTimeString(),
            'endsAt' => $offer->ends_at?->toDateTimeString(),
            'isActive' => $offer->is_active,
        ];
    }

    private function formatOfferBadge(SmOffer $offer): ?string
    {
        if ($offer->discount_percent !== null) {
            $percent = mb_rtrim(mb_rtrim((string) $offer->discount_percent, '0'), '.');

            return "خصم {$percent}%";
        }

        if ($offer->discount_value !== null) {
            $value = mb_rtrim(mb_rtrim((string) $offer->discount_value, '0'), '.');
            $currency = config('app.currency', 'IQD');

            return "خصم {$value} {$currency}";
        }

        return null;
    }
}
