<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantPenaltyRequests\RestaurantPenaltyFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantPenaltyResource;
use Modules\Resturants\Models\RestaurantPenalty;

final class RestaurantPenaltyController
{
    public function index(RestaurantPenaltyFilterRequest $request): AnonymousResourceCollection
    {
        $penalties = RestaurantPenalty::getQuery()
            ->with(['restaurant'])
            ->paginate($request->get('perPage', 20));

        return RestaurantPenaltyResource::collection($penalties);
    }

    public function show(RestaurantPenalty $restaurant_penalty): RestaurantPenaltyResource
    {
        $restaurant_penalty->load(['restaurant']);

        return RestaurantPenaltyResource::make($restaurant_penalty);
    }
}
