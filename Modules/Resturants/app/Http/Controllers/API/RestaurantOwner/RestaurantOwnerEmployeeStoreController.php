<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerEmployeeStoreRequest;
use Modules\Resturants\Services\RestaurantOwnerEmployeeService;
use Modules\Resturants\Support\RestaurantOwnerEmployeePayload;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeStoreController
{
    public function __invoke(
        OwnerEmployeeStoreRequest $request,
        RestaurantOwnerContext $context,
        RestaurantOwnerEmployeeService $employeeService
    ): JsonResponse {
        $restaurant = $context->restaurant();
        $validated = $request->validated();

        $staff = $employeeService->createOrLink(
            $restaurant,
            (string) $validated['name'],
            $validated['email'] ?? null,
            $validated['phone'] ?? null,
            (bool) ($validated['isActive'] ?? true),
            $validated['permissionIds'] ?? []
        );

        return response()->json([
            'data' => RestaurantOwnerEmployeePayload::make($staff),
            'message' => 'Employee saved successfully.',
        ], 201);
    }
}
