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
        int|string $employee,
        RestaurantOwnerContext $context
    ): JsonResponse {
        $employee = $this->resolveEmployee($employee, $context);
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
            if (array_key_exists('password', $validated) && is_string($validated['password']) && $validated['password'] !== '') {
                $userUpdates['password'] = $validated['password'];
            }

            if ($userUpdates !== [] && $employee->user) {
                $userUpdates['module_type'] = UserModuleType::RestaurantSeller->value;
                $employee->user->update($userUpdates);
            }

            if ($userUpdates === [] && $employee->user && $employee->user->module_type !== UserModuleType::RestaurantSeller) {
                $employee->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
            }

            if (array_key_exists('permissionIds', $validated) && $employee->user) {
                $employee->user->permissions()->sync($validated['permissionIds'] ?? []);
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

    private function resolveEmployee(int|string $employee, RestaurantOwnerContext $context): RestaurantStaff
    {
        $restaurant = $context->restaurant();

        $staff = RestaurantStaff::query()
            ->with('user')
            ->whereKey($employee)
            ->first();

        if ($staff !== null && (int) $staff->restaurant_id === (int) $restaurant->id) {
            return $staff;
        }

        $staffByUserId = RestaurantStaff::query()
            ->with('user')
            ->where('user_id', $employee)
            ->first();

        if ($staffByUserId !== null) {
            $context->ensureOwnedStaff($staffByUserId);

            return $staffByUserId;
        }

        if ($staff !== null) {
            $context->ensureOwnedStaff($staff);
        }

        abort(404);
    }
}
