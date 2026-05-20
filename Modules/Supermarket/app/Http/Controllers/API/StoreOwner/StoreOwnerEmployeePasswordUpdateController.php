<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Http\Requests\StoreOwnerEmployeePasswordUpdateRequest;
use Modules\Supermarket\Models\SmStoreStaff;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerEmployeePasswordUpdateController
{
    /**
     * @throws ValidationException
     */
    public function __invoke(
        StoreOwnerEmployeePasswordUpdateRequest $request,
        SmStoreStaff $staff,
        StoreOwnerContextService $context
    ): JsonResponse {
        $context->ensureOwnedStaff($staff);

        if (! $staff->user) {
            throw ValidationException::withMessages([
                'staff' => ['This employee is not linked to a user account.'],
            ]);
        }

        $staff->user->forceFill([
            'password' => $request->validated('newPassword'),
        ])->save();

        return response()->json([
            'message' => 'Employee password updated successfully.',
        ]);
    }
}
