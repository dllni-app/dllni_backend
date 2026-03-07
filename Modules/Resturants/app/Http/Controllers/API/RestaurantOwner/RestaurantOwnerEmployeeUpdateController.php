<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerEmployeeUpdateRequest;
use Modules\Resturants\Models\RestaurantRole;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerEmployeeUpdateController
{
    /** @throws ValidationException */
    public function __invoke(
        OwnerEmployeeUpdateRequest $request,
        RestaurantStaff $restaurant_staff,
        RestaurantOwnerContext $context
    ): JsonResponse {
        $context->ensureOwnedStaff($restaurant_staff);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $restaurant_staff): void {
            if (isset($validated['restaurantRoleId'])) {
                $role = RestaurantRole::query()->findOrFail((int) $validated['restaurantRoleId']);
                if ((int) $role->restaurant_id !== (int) $restaurant_staff->restaurant_id) {
                    throw ValidationException::withMessages([
                        'restaurantRoleId' => 'The selected role does not belong to this restaurant.',
                    ]);
                }

                $restaurant_staff->update(['restaurant_role_id' => $role->id]);
            }

            if (isset($validated['isActive'])) {
                $restaurant_staff->update(['is_active' => (bool) $validated['isActive']]);
            }

            $userUpdates = [];
            if (array_key_exists('name', $validated)) {
                $userUpdates['name'] = $validated['name'];
            }
            if (array_key_exists('email', $validated)) {
                $userUpdates['email'] = $validated['email'];
            }
            if (array_key_exists('phone', $validated)) {
                $userUpdates['phone'] = $validated['phone'];
            }

            if ($userUpdates !== [] && $restaurant_staff->user) {
                $restaurant_staff->user->update($userUpdates);
            }

            if (isset($validated['permissionIds']) && $restaurant_staff->role) {
                $restaurant_staff->role->permissions()->sync($validated['permissionIds']);
            }
        });

        $restaurant_staff->refresh()->load(['user', 'role.permissions']);

        return response()->json([
            'data' => [
                'id' => $restaurant_staff->id,
                'restaurantId' => $restaurant_staff->restaurant_id,
                'userId' => $restaurant_staff->user_id,
                'restaurantRoleId' => $restaurant_staff->restaurant_role_id,
                'isActive' => (bool) $restaurant_staff->is_active,
                'user' => [
                    'id' => $restaurant_staff->user?->id,
                    'name' => $restaurant_staff->user?->name,
                    'email' => $restaurant_staff->user?->email,
                    'phone' => $restaurant_staff->user?->phone,
                ],
                'role' => [
                    'id' => $restaurant_staff->role?->id,
                    'name' => $restaurant_staff->role?->name,
                    'slug' => $restaurant_staff->role?->slug,
                ],
                'effectivePermissions' => $restaurant_staff->role?->permissions?->pluck('name')->values()->all() ?? [],
                'createdAt' => $restaurant_staff->created_at?->toDateTimeString(),
                'updatedAt' => $restaurant_staff->updated_at?->toDateTimeString(),
            ],
            'message' => 'Employee updated successfully.',
        ]);
    }
}
