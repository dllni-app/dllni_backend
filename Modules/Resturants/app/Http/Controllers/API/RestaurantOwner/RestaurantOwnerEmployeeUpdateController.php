<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use App\Enums\UserModuleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerEmployeeUpdateRequest;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerEmployeePayload;

final class RestaurantOwnerEmployeeUpdateController
{
    public function __invoke(
        OwnerEmployeeUpdateRequest $request,
        RestaurantStaff $restaurant_staff,
        RestaurantOwnerContext $context
    ): JsonResponse {
        $context->ensureOwnedStaff($restaurant_staff);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $restaurant_staff): void {
            $staffUpdates = ['restaurant_role_id' => null];

            if (isset($validated['isActive'])) {
                $staffUpdates['is_active'] = (bool) $validated['isActive'];
            }

            $restaurant_staff->update($staffUpdates);

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
            if (array_key_exists('password', $validated) && is_string($validated['password'])) {
                $userUpdates['password'] = $validated['password'];
            }

            if ($userUpdates !== [] && $restaurant_staff->user) {
                $userUpdates['module_type'] = UserModuleType::RestaurantSeller->value;
                $restaurant_staff->user->update($userUpdates);
            }

            if ($userUpdates === [] && $restaurant_staff->user && $restaurant_staff->user->module_type !== UserModuleType::RestaurantSeller) {
                $restaurant_staff->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
            }

            if (isset($validated['permissionIds']) && $restaurant_staff->user) {
                $restaurant_staff->user->syncPermissions($validated['permissionIds']);
            }
        });

        if ($request->hasFile('profileImage') && $restaurant_staff->user) {
            $restaurant_staff->user->clearMediaCollection('primary-image');
            $restaurant_staff->user->addMediaFromRequest('profileImage')->toMediaCollection('primary-image');
        }

        $restaurant_staff->refresh()->load(['user.permissions', 'user.media']);

        return response()->json([
            'data' => RestaurantOwnerEmployeePayload::make($restaurant_staff),
            'message' => 'Employee updated successfully.',
        ]);
    }
}
