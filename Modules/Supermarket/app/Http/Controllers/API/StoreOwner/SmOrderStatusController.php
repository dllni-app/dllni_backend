<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Data\SmOrderRejectStatusData;
use Modules\Supermarket\Http\Requests\SmOrderRejectStatusRequest;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\SmOrderService;

final class SmOrderStatusController
{
    public function __construct(private SmOrderService $orderService) {}

    /**
     * Accept an order.
     *
     * Business logic is delegated to SmOrderService::acceptOrder()
     */
    public function accept(SmOrder $order): JsonResponse|JsonResource
    {
        try {
            $acceptedOrder = $this->orderService->acceptOrder($order);

            return SmOrderResource::make($acceptedOrder->load([
                'customer',
                'store',
                'coupon',
                'items.product',
                'statusLogs',
                'disputes',
            ]))->additional([
                'message' => 'Order accepted successfully.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reject an order with reason and type.
     *
     * Business logic is delegated to SmOrderService::rejectOrder()
     * All status transitions, trust score calculations, and notifications
     * are handled inside the service layer.
     */
    public function reject(SmOrderRejectStatusRequest $request, SmOrder $order): JsonResponse|JsonResource
    {
        try {
            $data = SmOrderRejectStatusData::from($request->validated());
            $rejectedOrder = $this->orderService->rejectOrder($order, $data);

            return SmOrderResource::make($rejectedOrder->load([
                'customer',
                'store',
                'coupon',
                'items.product',
                'statusLogs',
                'disputes',
            ]))->additional([
                'message' => 'Order rejected successfully.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
