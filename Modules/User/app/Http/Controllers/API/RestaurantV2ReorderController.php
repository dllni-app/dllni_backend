<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Http\Requests\RestaurantV2ReorderPreviewRequest;
use Modules\User\Services\RestaurantV2ReorderService;

final class RestaurantV2ReorderController
{
    public function preview(RestaurantV2ReorderPreviewRequest $request, int $orderId, RestaurantV2ReorderService $service): JsonResponse
    {
        return response()->json(['data' => $service->preview((int) $request->user()->id, $orderId, $request->validated())]);
    }

    public function apply(Request $request, int $orderId, RestaurantV2ReorderService $service): JsonResponse
    {
        $validated = $request->validate(['previewToken' => ['required', 'string', 'max:32000']]);

        return response()->json(['data' => $service->apply((int) $request->user()->id, $orderId, $validated['previewToken'])]);
    }
}
