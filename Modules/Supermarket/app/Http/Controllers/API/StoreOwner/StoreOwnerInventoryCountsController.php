<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerInventoryCountsController
{
    public function __construct(private readonly StoreOwnerContextService $context) {}

    public function __invoke(): JsonResponse
    {
        $storeId = $this->context->ownedStore()->id;
        $query = SmProduct::query()->where('store_id', $storeId);

        $total = (clone $query)->count();
        $available = (clone $query)->where('is_available', true)->count();
        $unavailable = (clone $query)->where('is_available', false)->count();
        $emptyStock = (clone $query)->where('stock_quantity', '<=', 0)->count();
        $lowStock = (clone $query)
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();
        $normal = (clone $query)
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '>', 'low_stock_threshold')
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Inventory counts retrieved successfully.',
            'data' => [
                'total' => $total,
                'normal' => $normal,
                'low_stock' => $lowStock,
                'out_of_stock' => $emptyStock,
                'available' => $available,
                'unavailable' => $unavailable,
            ],
        ]);
    }
}
