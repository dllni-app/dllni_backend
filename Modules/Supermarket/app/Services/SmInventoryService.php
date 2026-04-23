<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Supermarket\Data\SmInventoryAuditData;
use Modules\Supermarket\Data\SmOrderReturnData;
use Modules\Supermarket\Data\SmProductExpirationData;
use Modules\Supermarket\Data\SmStockUpdateData;
use Modules\Supermarket\Enums\SmInventoryLogType;
use Modules\Supermarket\Enums\SmStockOperation;
use Modules\Supermarket\Events\ReturnProcessed;
use Modules\Supermarket\Events\StockUpdated;
use Modules\Supermarket\Models\SmInventoryLog;
use Modules\Supermarket\Models\SmLostOpportunity;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmProduct;

final class SmInventoryService
{
    private const EXPIRATION_WARNING_DAYS = 7;

    private const DISCOUNT_PERCENTAGE_EXPIRING_SOON = 20;

    /**
     * Automatically deduct stock when order is accepted.
     */
    public function deductStockForOrder(SmOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            foreach ($order->items as $item) {
                $product = $item->product;

                if ($product->stock_quantity < $item->quantity) {
                    throw new Exception("Insufficient stock for product: {$product->name}");
                }

                $previousStock = $product->stock_quantity;
                $product->stock_quantity -= $item->quantity;
                $product->save();

                $this->logInventoryChange(
                    product: $product,
                    logType: SmInventoryLogType::OrderDeduction,
                    quantityChange: -$item->quantity,
                    quantityAfter: $product->stock_quantity,
                    reference: $order,
                    notes: "Stock deducted for order #{$order->order_number}",
                );

                $this->dispatchStockUpdatedEvent(
                    product: $product,
                    previousStock: $previousStock,
                    newStock: $product->stock_quantity,
                    reason: 'Order accepted'
                );
            }
        });
    }

    /**
     * Get products with low stock alert.
     */
    public function getLowStockProducts(int $storeId): array
    {
        $products = SmProduct::query()
            ->where('store_id', $storeId)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->where('is_available', true)
            ->with(['category'])
            ->orderBy('stock_quantity', 'asc')
            ->get();

        return $products->map(function (SmProduct $product): array {
            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'current_stock' => $product->stock_quantity,
                'threshold' => $product->low_stock_threshold,
                'category' => $product->category?->name,
                'barcode' => $product->barcode,
            ];
        })->toArray();
    }

    /**
     * Get inventory summary metrics for store owner dashboard.
     *
     * inventoryValue = SUM(stock_quantity * COALESCE(discounted_price, price))
     */
    public function getInventorySummary(int $storeId): array
    {
        $inventoryValue = (float) SmProduct::query()
            ->where('store_id', $storeId)
            ->selectRaw('COALESCE(SUM(stock_quantity * COALESCE(discounted_price, price)), 0) as total')
            ->value('total');

        $productSkus = SmProduct::query()
            ->where('store_id', $storeId)
            ->count();

        $lowStockCount = SmProduct::query()
            ->where('store_id', $storeId)
            ->where('is_available', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        return [
            'inventoryValue' => round($inventoryValue, 2),
            'productSkus' => $productSkus,
            'lowStockCount' => $lowStockCount,
        ];
    }

    /**
     * Manually update product stock.
     */
    public function updateStock(SmProduct $product, SmStockUpdateData $data, ?int $userId = null): SmProduct
    {
        return DB::transaction(function () use ($product, $data, $userId): SmProduct {
            $previousStock = $product->stock_quantity;

            $newStock = match ($data->operation) {
                SmStockOperation::SET => $data->quantity,
                SmStockOperation::INCREMENT => $previousStock + $data->quantity,
                SmStockOperation::DECREMENT => $previousStock - $data->quantity,
            };

            if ($newStock < 0) {
                throw new Exception('Stock quantity cannot be negative.');
            }

            $product->stock_quantity = $newStock;
            $product->save();

            $quantityChange = $newStock - $previousStock;

            $this->logInventoryChange(
                product: $product,
                logType: SmInventoryLogType::ManualAdjustment,
                quantityChange: $quantityChange,
                quantityAfter: $newStock,
                reference: null,
                notes: "Manual stock {$data->operation->value}: {$data->quantity}",
                userId: $userId
            );

            $this->dispatchStockUpdatedEvent(
                product: $product,
                previousStock: $previousStock,
                newStock: $newStock,
                reason: "Manual {$data->operation->value}"
            );

            return $product->fresh();
        });
    }

    /**
     * Perform inventory audit.
     */
    public function performAudit(SmInventoryAuditData $data, int $storeId, ?int $userId = null): array
    {
        $discrepancies = [];
        $totalCorrected = 0;

        DB::transaction(function () use ($data, $storeId, $userId, &$discrepancies, &$totalCorrected): void {
            foreach ($data->products as $auditItem) {
                $product = SmProduct::query()
                    ->where('id', $auditItem->product_id)
                    ->where('store_id', $storeId)
                    ->firstOrFail();

                $systemStock = $product->stock_quantity;
                $actualStock = $auditItem->actual_stock;
                $difference = $actualStock - $systemStock;

                if ($difference !== 0) {
                    $discrepancies[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'system_stock' => $systemStock,
                        'actual_stock' => $actualStock,
                        'difference' => $difference,
                    ];

                    $product->stock_quantity = $actualStock;
                    $product->save();

                    $this->logInventoryChange(
                        product: $product,
                        logType: SmInventoryLogType::AuditCorrection,
                        quantityChange: $difference,
                        quantityAfter: $actualStock,
                        reference: null,
                        notes: "Audit correction: System({$systemStock}) → Actual({$actualStock})",
                        userId: $userId
                    );

                    $totalCorrected++;
                }
            }
        });

        return [
            'total_audited' => $data->products->count(),
            'discrepancies_found' => count($discrepancies),
            'total_corrected' => $totalCorrected,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Update product expiration date and suggest discount if expiring soon.
     */
    public function updateExpiration(SmProduct $product, SmProductExpirationData $data): array
    {
        $product->expires_at = $data->expires_at;
        $product->save();

        $daysUntilExpiration = now()->diffInDays($data->expires_at, false);
        $isExpiringSoon = $daysUntilExpiration <= self::EXPIRATION_WARNING_DAYS && $daysUntilExpiration > 0;

        $suggestedDiscount = null;
        if ($isExpiringSoon) {
            $suggestedDiscount = [
                'discount_percentage' => self::DISCOUNT_PERCENTAGE_EXPIRING_SOON,
                'suggested_price' => round($product->price * (1 - self::DISCOUNT_PERCENTAGE_EXPIRING_SOON / 100), 2),
                'days_until_expiration' => (int) $daysUntilExpiration,
            ];
        }

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'expires_at' => $data->expires_at->toIso8601String(),
            'is_expiring_soon' => $isExpiringSoon,
            'suggested_discount' => $suggestedDiscount,
        ];
    }

    /**
     * Process order return and increase stock.
     */
    public function processReturn(SmOrder $order, SmOrderReturnData $data, ?int $userId = null): array
    {
        $returnedItems = [];

        DB::transaction(function () use ($order, $data, $userId, &$returnedItems): void {
            foreach ($data->items as $returnItem) {
                $orderItem = SmOrderItem::query()
                    ->where('id', $returnItem->order_item_id)
                    ->where('order_id', $order->id)
                    ->firstOrFail();

                if ($returnItem->quantity > $orderItem->quantity) {
                    throw new Exception("Return quantity exceeds ordered quantity for item #{$orderItem->id}");
                }

                $product = $orderItem->product;
                $previousStock = $product->stock_quantity;

                $product->stock_quantity += $returnItem->quantity;
                $product->save();

                $this->logInventoryChange(
                    product: $product,
                    logType: SmInventoryLogType::Return,
                    quantityChange: $returnItem->quantity,
                    quantityAfter: $product->stock_quantity,
                    reference: $order,
                    notes: "Return for order #{$order->order_number}: {$data->reason}",
                    userId: $userId
                );

                $returnedItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'returned_quantity' => $returnItem->quantity,
                    'new_stock' => $product->stock_quantity,
                ];

                $this->dispatchStockUpdatedEvent(
                    product: $product,
                    previousStock: $previousStock,
                    newStock: $product->stock_quantity,
                    reason: 'Product returned'
                );
            }
        });

        try {
            event(new ReturnProcessed(
                order: $order,
                returnedItems: $returnedItems,
                reason: $data->reason
            ));
        } catch (Exception $e) {
            Log::error('Failed to dispatch ReturnProcessed event', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'returned_items' => $returnedItems,
            'reason' => $data->reason,
        ];
    }

    /**
     * Get lost opportunities report.
     */
    public function getLostOpportunities(int $storeId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = SmLostOpportunity::query()
            ->where('store_id', $storeId)
            ->with(['product:id,name,barcode', 'customer:id,name']);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $opportunities = $query->orderBy('created_at', 'desc')->get();

        // Group by product for frequency analysis
        $byProduct = $opportunities->groupBy('product_id')->map(function ($items): array {
            $product = $items->first()->product;

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'barcode' => $product->barcode,
                'total_attempts' => $items->count(),
                'total_attempted_quantity' => $items->sum('attempted_quantity'),
                'latest_attempt' => $items->first()->created_at->toIso8601String(),
            ];
        })->values();

        return [
            'total_lost_opportunities' => $opportunities->count(),
            'by_product' => $byProduct->toArray(),
            'recent_opportunities' => $opportunities->take(20)->map(function (SmLostOpportunity $opp): array {
                return [
                    'product_id' => $opp->product_id,
                    'product_name' => $opp->product->name,
                    'attempted_quantity' => $opp->attempted_quantity,
                    'available_stock' => $opp->available_stock,
                    'date' => $opp->created_at->toIso8601String(),
                    'customer_name' => $opp->customer?->name ?? 'Guest',
                ];
            })->toArray(),
        ];
    }

    /**
     * Track lost opportunity when customer attempts order with insufficient stock.
     */
    public function trackLostOpportunity(
        int $storeId,
        int $productId,
        int $attemptedQuantity,
        int $availableStock,
        ?int $customerId = null
    ): void {
        try {
            SmLostOpportunity::create([
                'store_id' => $storeId,
                'product_id' => $productId,
                'customer_id' => $customerId,
                'attempted_quantity' => $attemptedQuantity,
                'available_stock' => $availableStock,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to track lost opportunity', [
                'product_id' => $productId,
                'attempted_quantity' => $attemptedQuantity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log inventory change.
     */
    private function logInventoryChange(
        SmProduct $product,
        SmInventoryLogType $logType,
        int $quantityChange,
        int $quantityAfter,
        mixed $reference = null,
        ?string $notes = null,
        ?int $userId = null
    ): void {
        SmInventoryLog::create([
            'product_id' => $product->id,
            'type' => $logType->value,
            'quantity_change' => $quantityChange,
            'quantity_after' => $quantityAfter,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
            'notes' => $notes,
            'user_id' => $userId,
        ]);
    }

    /**
     * Dispatch StockUpdated event (fail-safe).
     */
    private function dispatchStockUpdatedEvent(
        SmProduct $product,
        int $previousStock,
        int $newStock,
        string $reason
    ): void {
        try {
            event(new StockUpdated(
                product: $product,
                previousStock: $previousStock,
                newStock: $newStock,
                reason: $reason
            ));
        } catch (Exception $e) {
            Log::error('Failed to dispatch StockUpdated event', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
