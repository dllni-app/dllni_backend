<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\RestaurantData;
use Modules\Resturants\Http\Requests\RestaurantRequest;
use Modules\Resturants\Http\Requests\RestaurantRequests\RestaurantFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Services\RestaurantService;
use Throwable;

final class RestaurantController
{
    public function __construct(
        private RestaurantService $restaurantService
    ) {}

    public function index(RestaurantFilterRequest $request): AnonymousResourceCollection
    {
        $restaurants = Restaurant::getQuery()
            ->with(['user', 'cuisineTypes'])
            ->paginate($request->get('perPage', 20));

        return RestaurantResource::collection($restaurants);
    }

    /** @throws Throwable */
    public function store(RestaurantRequest $request): RestaurantResource
    {
        $restaurant = $this->restaurantService->store(
            RestaurantData::from($request->validated())
        );

        return RestaurantResource::make(
            $restaurant->load(['user', 'operatingHours', 'documents', 'cuisineTypes', 'reputationLogs', 'penalties'])
        );
    }

    public function show(Restaurant $restaurant): RestaurantResource
    {
        $restaurant->load([
            'user', 'operatingHours', 'documents', 'cuisineTypes', 'reputationLogs', 'penalties',
        ]);

        return RestaurantResource::make($restaurant);
    }

    /** @throws Throwable */
    public function update(RestaurantRequest $request, Restaurant $restaurant): RestaurantResource
    {
        $updated = $this->restaurantService->update(
            RestaurantData::from($request->validated()),
            $restaurant
        );

        return RestaurantResource::make(
            $updated->load(['user', 'operatingHours', 'documents', 'cuisineTypes', 'reputationLogs', 'penalties'])
        );
    }

    public function destroy(Restaurant $restaurant): Response
    {
        $restaurant->delete();

        return response()->noContent();
    }
}
