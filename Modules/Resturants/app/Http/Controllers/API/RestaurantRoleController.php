<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\RestaurantRoleData;
use Modules\Resturants\Http\Requests\RestaurantRolePermissionsRequest;
use Modules\Resturants\Http\Requests\RestaurantRoleRequest;
use Modules\Resturants\Http\Requests\RestaurantRoleRequests\RestaurantRoleFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantRoleResource;
use Modules\Resturants\Models\RestaurantRole;
use Modules\Resturants\Services\RestaurantRoleService;
use Throwable;

final class RestaurantRoleController
{
    public function __construct(
        private RestaurantRoleService $restaurantRoleService
    ) {}

    public function index(RestaurantRoleFilterRequest $request): AnonymousResourceCollection
    {
        $roles = RestaurantRole::getQuery()
            ->with(['restaurant', 'staff'])
            ->paginate($request->get('perPage', 20));

        return RestaurantRoleResource::collection($roles);
    }

    /** @throws Throwable */
    public function store(RestaurantRoleRequest $request): RestaurantRoleResource
    {
        $role = $this->restaurantRoleService->store(
            RestaurantRoleData::from($request->validated())
        );

        return RestaurantRoleResource::make(
            $role->load(['restaurant', 'staff'])
        );
    }

    public function show(RestaurantRole $restaurant_role): RestaurantRoleResource
    {
        $restaurant_role->load(['restaurant', 'staff', 'permissions']);

        return RestaurantRoleResource::make($restaurant_role);
    }

    /** @throws Throwable */
    public function update(RestaurantRoleRequest $request, RestaurantRole $restaurant_role): RestaurantRoleResource
    {
        $updated = $this->restaurantRoleService->update(
            RestaurantRoleData::from($request->validated()),
            $restaurant_role
        );

        return RestaurantRoleResource::make(
            $updated->load(['restaurant', 'staff'])
        );
    }

    public function destroy(RestaurantRole $restaurant_role): Response
    {
        $restaurant_role->delete();

        return response()->noContent();
    }

    public function updatePermissions(RestaurantRolePermissionsRequest $request, RestaurantRole $restaurant_role): RestaurantRoleResource
    {
        $restaurant_role->permissions()->sync($request->validated('permissionIds'));

        return RestaurantRoleResource::make(
            $restaurant_role->load(['restaurant', 'staff', 'permissions'])
        );
    }
}
