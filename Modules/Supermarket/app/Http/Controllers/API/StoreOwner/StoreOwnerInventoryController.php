<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Supermarket\Data\SmInventoryAuditData;
use Modules\Supermarket\Data\SmOrderReturnData;
use Modules\Supermarket\Data\SmProductExpirationData;
use Modules\Supermarket\Data\SmStockUpdateData;
use Modules\Supermarket\Http\Requests\SmInventoryAuditRequest;
use Modules\Supermarket\Http\Requests\SmOrderReturnRequest;
use Modules\Supermarket\Http\Requests\SmProductExpirationRequest;
use Modules\Supermarket\Http\Requests\SmStockUpdateRequest;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Services\SmInventoryService;

final class StoreOwnerInventoryController
{
    public function __construct(
        private readonly SmInventoryService $inventoryService
    ) {}

    /**
     * Get low stock products for the store.
     *
     * GET /api/v1/store-owner/products/low-stock?store_id={id}
     */
    public function lowStock(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:sm_stores,id'],
        ]);

        $storeId = (int) $request->input('store_id');

        try {
            $lowStockProducts = $this->inventoryService->getLowStockProducts($storeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $lowStockProducts,
                    'total' => count($lowStockProducts),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve low stock products.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Manually update product stock.
     *
     * PUT /api/v1/store-owner/products/{product}/stock
     */
    public function updateStock(SmStockUpdateRequest $request, SmProduct $product): JsonResponse
    {
        try {
            $data = SmStockUpdateData::from($request->validated());

            $userId = $request->user()?->id;

            $updatedProduct = $this->inventoryService->updateStock($product, $data, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Stock updated successfully.',
                'data' => [
                    'product_id' => $updatedProduct->id,
                    'product_name' => $updatedProduct->name,
                    'stock_quantity' => $updatedProduct->stock_quantity,
                    'low_stock_threshold' => $updatedProduct->low_stock_threshold,
                    'is_low_stock' => $updatedProduct->stock_quantity <= $updatedProduct->low_stock_threshold,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Perform inventory audit.
     *
     * POST /api/v1/store-owner/inventory/audit
     */
    public function audit(SmInventoryAuditRequest $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:sm_stores,id'],
        ]);

        try {
            $data = SmInventoryAuditData::from($request->validated());
            $storeId = (int) $request->input('store_id');
            $userId = $request->user()?->id;

            $auditResults = $this->inventoryService->performAudit($data, $storeId, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Inventory audit completed successfully.',
                'data' => $auditResults,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform inventory audit.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update product expiration date.
     *
     * PUT /api/v1/store-owner/products/{product}/expiration
     */
    public function updateExpiration(SmProductExpirationRequest $request, SmProduct $product): JsonResponse
    {
        try {
            $data = SmProductExpirationData::from($request->validated());

            $result = $this->inventoryService->updateExpiration($product, $data);

            return response()->json([
                'success' => true,
                'message' => 'Product expiration updated successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product expiration.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Process order return.
     *
     * POST /api/v1/store-owner/orders/{order}/return
     */
    public function processReturn(SmOrderReturnRequest $request, SmOrder $order): JsonResponse
    {
        try {
            $data = SmOrderReturnData::from($request->validated());
            $userId = $request->user()?->id;

            $result = $this->inventoryService->processReturn($order, $data, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Order return processed successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process return.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get lost opportunities report.
     *
     * GET /api/v1/store-owner/reports/lost-opportunities?store_id={id}&start_date={date}&end_date={date}
     */
    public function lostOpportunities(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:sm_stores,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            $storeId = (int) $request->input('store_id');
            $startDate = $request->filled('start_date') ? Carbon::parse($request->input('start_date')) : null;
            $endDate = $request->filled('end_date') ? Carbon::parse($request->input('end_date')) : null;

            $report = $this->inventoryService->getLostOpportunities($storeId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve lost opportunities report.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
