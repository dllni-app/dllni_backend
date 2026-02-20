<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantAssistantQueryRequests\RestaurantAssistantQueryFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantAssistantQueryResource;
use Modules\Resturants\Models\RestaurantAssistantQuery;

final class RestaurantAssistantQueryController
{
    public function index(RestaurantAssistantQueryFilterRequest $request): AnonymousResourceCollection
    {
        $queries = RestaurantAssistantQuery::getQuery()
            ->with(['user', 'restaurant', 'matchedRecipe'])
            ->paginate($request->get('perPage', 20));

        return RestaurantAssistantQueryResource::collection($queries);
    }

    public function show(RestaurantAssistantQuery $restaurant_assistant_query): RestaurantAssistantQueryResource
    {
        $restaurant_assistant_query->load(['user', 'restaurant', 'matchedRecipe']);

        return RestaurantAssistantQueryResource::make($restaurant_assistant_query);
    }
}
