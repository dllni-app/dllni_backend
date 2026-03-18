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
use Modules\Resturants\Support\RestaurantOwnerContext;
use Throwable;

final class RestaurantController
{
    public function __construct(
        private RestaurantService $restaurantService,
        private RestaurantOwnerContext $ownerContext
    ) {}

    public function index(RestaurantFilterRequest $request): AnonymousResourceCollection
    {
        $restaurants = Restaurant::getQuery()
            ->with(['media', 'user', 'cuisineTypes'])
            ->paginate($request->get('perPage', 20));

        return RestaurantResource::collection($restaurants);
    }

    /** @throws Throwable */
    public function store(RestaurantRequest $request): RestaurantResource
    {
        $primaryImage = $request->file('primaryImage');

        $restaurant = $this->restaurantService->store(
            RestaurantData::from([
                ...$request->validated(),
                'primaryImage' => $primaryImage,
            ])
        );

        return RestaurantResource::make(
            $restaurant->load(['media', 'user', 'operatingHours', 'documents', 'cuisineTypes', 'reputationLogs', 'penalties'])
        );
    }

    public function show(): RestaurantResource
    {
        $restaurant = $this->ownerContext->restaurant();

        $restaurant->load([
            'media', 'user', 'operatingHours', 'documents', 'cuisineTypes', 'reputationLogs', 'penalties',
        ]);

        return RestaurantResource::make($restaurant);
    }

    /** @throws Throwable */
    public function update(RestaurantRequest $request): RestaurantResource
    {
        $restaurant = $this->ownerContext->restaurant();

        $updated = $this->restaurantService->update(
            RestaurantData::from([
                ...$request->validated(),
                'userId' => $restaurant->user_id,
                'primaryImage' => $request->file('primaryImage'),
                'images' => $request->file('images'),
            ]),
            $restaurant
        );

        return RestaurantResource::make(
            $updated->load(['media', 'user', 'operatingHours', 'documents', 'cuisineTypes', 'reputationLogs', 'penalties'])
        );
    }

    public function destroy(Restaurant $restaurant): Response
    {
        $restaurant->delete();

        return response()->noContent();
    }
}
