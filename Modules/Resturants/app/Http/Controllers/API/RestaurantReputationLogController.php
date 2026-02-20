<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantReputationLogRequests\RestaurantReputationLogFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantReputationLogResource;
use Modules\Resturants\Models\RestaurantReputationLog;

final class RestaurantReputationLogController
{
    public function index(RestaurantReputationLogFilterRequest $request): AnonymousResourceCollection
    {
        $logs = RestaurantReputationLog::getQuery()
            ->with(['restaurant'])
            ->paginate($request->get('perPage', 20));

        return RestaurantReputationLogResource::collection($logs);
    }

    public function show(RestaurantReputationLog $restaurant_reputation_log): RestaurantReputationLogResource
    {
        $restaurant_reputation_log->load(['restaurant']);

        return RestaurantReputationLogResource::make($restaurant_reputation_log);
    }
}
