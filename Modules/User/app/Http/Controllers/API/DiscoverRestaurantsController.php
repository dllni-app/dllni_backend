<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Http\Requests\DiscoverRestaurantsRequest;

final class DiscoverRestaurantsController
{
    public function __invoke(DiscoverRestaurantsRequest $request): AnonymousResourceCollection
    {
        $now = CarbonImmutable::now();
        $query = Restaurant::query()
            ->where('is_active', true)
            ->with(['media', 'cuisineTypes', 'primaryActiveOffer']);

        $search = $request->validated('search');
        if (is_string($search) && $search !== '') {
            $escaped = addcslashes($search, '%_\\');

            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$escaped}%")
                ->orWhere('description', 'like', "%{$escaped}%")
                ->orWhere('city', 'like', "%{$escaped}%")
                ->orWhere('district', 'like', "%{$escaped}%"));
        }

        if ($request->boolean('filter.hasOffers')) {
            $query->whereHas('offers', fn ($offers) => $offers
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now)));
        }

        if ($request->boolean('filter.openNow')) {
            $dayOfWeek = Str::lower($now->englishDayOfWeek);
            $time = $now->format('H:i:s');

            $query
                ->where('is_temporarily_closed', false)
                ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', $now))
                ->whereHas('operatingHours', fn ($hours) => $hours
                    ->where('day_of_week', $dayOfWeek)
                    ->where('is_closed', false)
                    ->whereNotNull('open_time')
                    ->whereNotNull('close_time')
                    ->where('open_time', '<=', $time)
                    ->where('close_time', '>=', $time));
        }

        $sort = $request->validated('sort') ?? 'rating';

        match ($sort) {
            'nearest' => $this->applyNearestSort($query, $request),
            'fastest' => $query->orderBy('estimated_preparation_time'),
            default => $query->orderByDesc('average_rating')->orderByDesc('is_featured'),
        };

        $restaurants = $query->paginate($request->integer('perPage', 10));

        return RestaurantResource::collection($restaurants);
    }

    private function applyNearestSort($query, DiscoverRestaurantsRequest $request): void
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
            ->select('restaurants.*')
            ->selectRaw(
                '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distanceKm',
                [$lat, $lng, $lat]
            )
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('distanceKm');
    }
}
