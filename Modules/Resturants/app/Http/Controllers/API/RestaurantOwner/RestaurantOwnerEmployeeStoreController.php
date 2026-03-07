<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerEmployeeStoreRequest;
use Modules\Resturants\Models\RestaurantRole;
use Modules\Resturants\Services\RestaurantOwnerEmployeeService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeStoreController
{
    /** @throws ValidationException */
    public function __invoke(
        OwnerEmployeeStoreRequest $request,
        RestaurantOwnerContext $context,
        RestaurantOwnerEmployeeService $employeeService
    ): JsonResponse {
        $restaurant = $context->restaurant();
        $validated = $request->validated();

        $role = RestaurantRole::query()->findOrFail((int) $validated['restaurantRoleId']);
        if ((int) $role->restaurant_id !== (int) $restaurant->id) {
            throw ValidationException::withMessages([
                'restaurantRoleId' => 'The selected role does not belong to this restaurant.',
            ]);
        }

        $staff = $employeeService->createOrLink(
            $restaurant,
            $role->id,
            (string) $validated['name'],
            $validated['email'] ?? null,
            $validated['phone'] ?? null,
            (bool) ($validated['isActive'] ?? true)
        );

        return response()->json([
            'data' => [
                'id' => $staff->id,
                'restaurantId' => $staff->restaurant_id,
                'userId' => $staff->user_id,
                'restaurantRoleId' => $staff->restaurant_role_id,
                'isActive' => (bool) $staff->is_active,
                'user' => [
                    'id' => $staff->user?->id,
                    'name' => $staff->user?->name,
                    'email' => $staff->user?->email,
                    'phone' => $staff->user?->phone,
                ],
                'role' => [
                    'id' => $staff->role?->id,
                    'name' => $staff->role?->name,
                    'slug' => $staff->role?->slug,
                ],
                'effectivePermissions' => $staff->role?->permissions?->pluck('name')->values()->all() ?? [],
                'createdAt' => $staff->created_at?->toDateTimeString(),
                'updatedAt' => $staff->updated_at?->toDateTimeString(),
            ],
            'message' => 'Employee saved successfully.',
        ], 201);
    }
}
