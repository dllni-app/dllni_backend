<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantStaffRequests\RestaurantStaffFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantStaffResource;
use Modules\Resturants\Models\RestaurantStaff;

final class RestaurantStaffController
{
    public function index(RestaurantStaffFilterRequest $request): AnonymousResourceCollection
    {
        $staff = RestaurantStaff::getQuery()
            ->with(['restaurant', 'user', 'role'])
            ->paginate($request->get('perPage', 20));

        return RestaurantStaffResource::collection($staff);
    }

    public function show(RestaurantStaff $restaurant_staff): RestaurantStaffResource
    {
        $restaurant_staff->load(['restaurant', 'user', 'role']);

        return RestaurantStaffResource::make($restaurant_staff);
    }
}
