<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Requests\StoreOwnerEmployeeStatusRequest;
use Modules\Supermarket\Models\SmStoreStaff;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Modules\Supermarket\Services\StoreOwnerEmployeePayload;

final class StoreOwnerEmployeeStatusController
{
    public function __invoke(
        StoreOwnerEmployeeStatusRequest $request,
        SmStoreStaff $staff,
        StoreOwnerContextService $context
    ): JsonResponse {
        $context->ensureOwnedStaff($staff);

        $validated = $request->validated();

        $staff->update([
            'is_active' => (bool) $validated['isActive'],
        ]);

        $staff->refresh()->load(['user.permissions']);

        return response()->json([
            'data' => StoreOwnerEmployeePayload::make($staff),
            'message' => 'Employee status updated successfully.',
        ]);
    }
}
