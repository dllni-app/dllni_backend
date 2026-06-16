<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Enums\UserModuleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Http\Requests\StoreOwnerEmployeeUpdateRequest;
use Modules\Supermarket\Models\SmStoreStaff;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Modules\Supermarket\Services\StoreOwnerEmployeePayload;

final class StoreOwnerEmployeeUpdateController
{
    public function __invoke(
        StoreOwnerEmployeeUpdateRequest $request,
        SmStoreStaff $staff,
        StoreOwnerContextService $context
    ): JsonResponse {
        $context->ensureOwnedStaff($staff);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $staff): void {
            if (isset($validated['isActive'])) {
                $staff->update(['is_active' => (bool) $validated['isActive']]);
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

            if ($userUpdates !== [] && $staff->user) {
                $userUpdates['module_type'] = UserModuleType::SupermarketSeller->value;
                $staff->user->update($userUpdates);
            }

            if ($userUpdates === [] && $staff->user && $staff->user->module_type !== UserModuleType::SupermarketSeller) {
                $staff->user->update(['module_type' => UserModuleType::SupermarketSeller->value]);
            }

            if (isset($validated['permissionIds']) && $staff->user) {
                $staff->user->permissions()->sync($validated['permissionIds']);
            }
        });

        if ($request->hasFile('profileImage') && $staff->user) {
            $staff->user->clearMediaCollection('primary-image');
            $staff->user->addMediaFromRequest('profileImage')->toMediaCollection('primary-image');
        }

        $staff->refresh()->load(['user.permissions']);

        return response()->json([
            'data' => StoreOwnerEmployeePayload::make($staff),
            'message' => 'Employee updated successfully.',
        ]);
    }
}
