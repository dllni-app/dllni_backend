<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\InventoryAlertsRequest;
use Modules\Resturants\Http\Resources\InventoryItemResource;
use Modules\Resturants\Models\InventoryItem;

final class InventoryAlertsController
{
    public function __invoke(InventoryAlertsRequest $request): JsonResponse
    {
        $restaurantId = (int) $request->validated('restaurantId');

        $items = InventoryItem::query()
            ->where('restaurant_id', $restaurantId)
            ->lowStock(true)
            ->with(['restaurant'])
            ->get();

        return response()->json([
            'data' => InventoryItemResource::collection($items),
        ]);
    }
}
