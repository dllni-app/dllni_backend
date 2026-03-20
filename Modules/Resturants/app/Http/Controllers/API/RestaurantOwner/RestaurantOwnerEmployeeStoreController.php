<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerEmployeeStoreRequest;
use Modules\Resturants\Services\RestaurantOwnerEmployeeService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerEmployeePayload;

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
            (string) $validated['password'],
            (bool) ($validated['isActive'] ?? true),
            $validated['permissionIds'] ?? []
        );

        if ($request->hasFile('profileImage') && $staff->user) {
            $staff->user->clearMediaCollection('primary-image');
            $staff->user->addMediaFromRequest('profileImage')->toMediaCollection('primary-image');
        }

        $staff->refresh()->load(['user.permissions', 'user.media']);

        return response()->json([
            'data' => RestaurantOwnerEmployeePayload::make($staff),
            'message' => 'Employee saved successfully.',
        ], 201);
    }
}
