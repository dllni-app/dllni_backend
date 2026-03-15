<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Supermarket\Models\SmStoreStaff;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Modules\Supermarket\Services\StoreOwnerEmployeePayload;

final class StoreOwnerEmployeeIndexController
{
    public function __invoke(Request $request, StoreOwnerContextService $context): JsonResponse
    {
        $validated = $request->validate([
            'storeId' => 'required|integer|exists:sm_stores,id',
        ]);

        $store = $context->store((int) $validated['storeId']);

        $employees = SmStoreStaff::query()
            ->where('store_id', $store->id)
            ->with(['user.permissions'])
            ->latest()
            ->get()
            ->map(static fn (SmStoreStaff $staff): array => StoreOwnerEmployeePayload::make($staff))
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'employees' => $employees,
            ],
        ]);
    }
}
