<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Http\Requests\DiscoverSupermarketStoresRequest;

final class SmStoresIndexController
{
    public function __invoke(DiscoverSupermarketStoresRequest $request): AnonymousResourceCollection
    {
        $now = CarbonImmutable::now();

        $query = SmStore::getQuery()
            ->where('is_active', true)
            ->with('owner')
            ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', $now));

        $search = $request->validated('search');
        if (is_string($search) && $search !== '') {
            $query->search($search);
        }

        $sort = $request->validated('sort') ?? 'rating';

        match ($sort) {
            'nearest' => $this->applyNearestSort($query, $request),
            default => $query->orderByDesc('average_rating')->orderByDesc('is_featured'),
        };

        $stores = $query->paginate($request->integer('perPage', 20));

        return SmStoreResource::collection($stores);
    }

    private function applyNearestSort($query, DiscoverSupermarketStoresRequest $request): void
    {
        $latitude = $request->validated('latitude');
        $longitude = $request->validated('longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            $query->orderByDesc('average_rating')->orderByDesc('is_featured');

            return;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        $query
            ->select('sm_stores.*')
            ->selectRaw(
                '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distanceKm',
                [$lat, $lng, $lat]
            )
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('distanceKm');
    }
}
