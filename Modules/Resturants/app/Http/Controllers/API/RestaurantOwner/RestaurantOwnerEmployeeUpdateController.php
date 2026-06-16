<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use App\Enums\UserModuleType;
use App\Models\User;
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
        User $user,
        RestaurantOwnerContext $context
    ): JsonResponse {
        /** @var RestaurantStaff $employee */
        $employee = $user->restaurantStaff()->firstOrFail();
        $context->ensureOwnedStaff($employee);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $employee): void {
            $staffUpdates = ['restaurant_role_id' => null];

            if (isset($validated['isActive'])) {
                $staffUpdates['is_active'] = (bool) $validated['isActive'];
            }

            $employee->update($staffUpdates);

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

            if ($userUpdates !== [] && $employee->user) {
                $userUpdates['module_type'] = UserModuleType::RestaurantSeller->value;
                $employee->user->update($userUpdates);
            }

            if ($userUpdates === [] && $employee->user && $employee->user->module_type !== UserModuleType::RestaurantSeller) {
                $employee->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
            }

            if (isset($validated['permissionIds']) && $employee->user) {
                $employee->user->permissions()->sync($validated['permissionIds']);
            }
        });

        if ($request->hasFile('profileImage') && $employee->user) {
            $employee->user->clearMediaCollection('primary-image');
            $employee->user->addMediaFromRequest('profileImage')->toMediaCollection('primary-image');
        }

        $employee->refresh()->load(['user.permissions', 'user.media']);

        return response()->json([
            'data' => RestaurantOwnerEmployeePayload::make($employee),
            'message' => 'Employee updated successfully.',
        ]);
    }
}
