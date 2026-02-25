<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\RestaurantStaffData;
use Modules\Resturants\Http\Requests\RestaurantStaffRequest;
use Modules\Resturants\Http\Requests\RestaurantStaffRequests\RestaurantStaffFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantStaffResource;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Services\RestaurantStaffService;
use Throwable;

final class RestaurantStaffController
{
    public function __construct(
        private RestaurantStaffService $restaurantStaffService
    ) {}

    public function index(RestaurantStaffFilterRequest $request): AnonymousResourceCollection
    {
        $staff = RestaurantStaff::getQuery()
            ->with(['restaurant', 'user', 'role'])
            ->paginate($request->get('perPage', 20));

        return RestaurantStaffResource::collection($staff);
    }

    /** @throws Throwable */
    public function store(RestaurantStaffRequest $request): RestaurantStaffResource
    {
        $staff = $this->restaurantStaffService->store(
            RestaurantStaffData::from($request->validated())
        );

        return RestaurantStaffResource::make(
            $staff->load(['restaurant', 'user', 'role'])
        );
    }

    public function show(RestaurantStaff $restaurant_staff): RestaurantStaffResource
    {
        $restaurant_staff->load(['restaurant', 'user', 'role']);

        return RestaurantStaffResource::make($restaurant_staff);
    }

    /** @throws Throwable */
    public function update(RestaurantStaffRequest $request, RestaurantStaff $restaurant_staff): RestaurantStaffResource
    {
        $updated = $this->restaurantStaffService->update(
            RestaurantStaffData::from($request->validated()),
            $restaurant_staff
        );

        return RestaurantStaffResource::make(
            $updated->load(['restaurant', 'user', 'role'])
        );
    }

    public function destroy(RestaurantStaff $restaurant_staff): Response
    {
        $restaurant_staff->delete();

        return response()->noContent();
    }
}
