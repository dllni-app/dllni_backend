<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantRoleRequests\RestaurantRoleFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantRoleResource;
use Modules\Resturants\Models\RestaurantRole;

final class RestaurantRoleController
{
    public function index(RestaurantRoleFilterRequest $request): AnonymousResourceCollection
    {
        $roles = RestaurantRole::getQuery()
            ->with(['restaurant', 'staff'])
            ->paginate($request->get('perPage', 20));

        return RestaurantRoleResource::collection($roles);
    }

    public function show(RestaurantRole $restaurant_role): RestaurantRoleResource
    {
        $restaurant_role->load(['restaurant', 'staff']);

        return RestaurantRoleResource::make($restaurant_role);
    }
}
