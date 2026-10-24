<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\InventorySummaryRequest;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class InventorySummaryController
{
    public function __invoke(InventorySummaryRequest $request, RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();

        $baseQuery = InventoryItem::query()->where('restaurant_id', $restaurant->id);

        $totalItems = (clone $baseQuery)->count();
        $lowStockCount = (clone $baseQuery)->lowStock(true)->count();
        // The client labels this legacy field as exhausted inventory, not expiry.
        $expiringCount = (clone $baseQuery)->where('quantity', '<=', 0)->count();
        $totalValue = (float) (clone $baseQuery)->get()->sum(fn ($item) => (float) $item->quantity * (float) $item->unit_cost);

        return response()->json([
            'data' => [
                'totalItems' => $totalItems,
                'lowStockCount' => $lowStockCount,
                'expiringItemsCount' => $expiringCount,
                'totalValue' => round($totalValue, 2),
            ],
        ]);
    }
}
