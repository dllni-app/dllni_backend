<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Requests\StoreOwnerEmployeeStoreRequest;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Modules\Supermarket\Services\StoreOwnerEmployeePayload;
use Modules\Supermarket\Services\StoreOwnerEmployeeService;

final class StoreOwnerEmployeeStoreController
{
    public function __invoke(
        StoreOwnerEmployeeStoreRequest $request,
        StoreOwnerContextService $context,
        StoreOwnerEmployeeService $employeeService
    ): JsonResponse {
        $validated = $request->validated();

        $store = $context->store((int) $validated['storeId']);

        $staff = $employeeService->createOrLink(
            $store,
            $validated['name'],
            $validated['email'] ?? null,
            $validated['phone'] ?? null,
            $validated['isActive'] ?? true,
            $validated['permissionIds'] ?? []
        );

        return response()->json([
            'data' => StoreOwnerEmployeePayload::make($staff),
            'message' => 'Employee created successfully.',
        ], 201);
    }
}
