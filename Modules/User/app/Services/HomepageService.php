<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Modules\Supermarket\Http\Resources\SmOfferResource;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmStore;

final class HomepageService
{
    /**
     * @return array<string, mixed>
     */
    public function supermarketFeaturedOffers(Request $request): array
    {
        $now = CarbonImmutable::now();

        $supermarketOffers = SmOffer::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->latest('starts_at')
            ->with('store')
            ->limit(10)
            ->get();

        return [
            'offers' => SmOfferResource::collection($supermarketOffers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supermarketNearbyStores(Request $request): array
    {
        $storesNearby = SmStore::query()
            ->where('is_active', true)
            ->orderByDesc('is_featured')
            ->orderByDesc('average_rating')
            ->limit(10)
            ->get();

        return [
            'stores' => SmStoreResource::collection($storesNearby),
        ];
    }
}
