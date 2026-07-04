<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Services\ActivityLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Data\SmOrderRejectStatusData;
use Modules\Supermarket\Http\Requests\SmOrderRejectStatusRequest;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\SmOrderNotificationService;
use Modules\Supermarket\Services\SmOrderService;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class SmOrderStatusController
{
    public function __construct(
        private SmOrderService $orderService,
        private ActivityLogService $activityLogService,
        private StoreOwnerContextService $context,
        private SmOrderNotificationService $notifications,
    ) {}

    /**
     * Accept an order.
     *
     * Business logic is delegated to SmOrderService::acceptOrder()
     */
    public function accept(SmOrder $order): JsonResponse|JsonResource
    {
        $this->context->store((int) $order->store_id);
        $previousStatus = $this->statusValue($order);

        try {
            $acceptedOrder = $this->orderService->acceptOrder($order);
            $this->activityLogService->logSmOrderAccepted((int) $order->id, $order->order_number, (int) $order->store_id);
            $this->notifications->notifyStatusChanged($acceptedOrder, $previousStatus, $this->statusValue($acceptedOrder), 'owner');

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
     * Hand order to courier (ready_for_pickup → picked_up).
     */
    public function courierHandover(SmOrder $order): JsonResponse|JsonResource
    {
        $this->context->store((int) $order->store_id);
        $previousStatus = $this->statusValue($order);

        try {
            $updated = $this->orderService->handOverToCourier($order, $this->context->owner()->id);
            $this->notifications->notifyStatusChanged($updated, $previousStatus, $this->statusValue($updated), 'owner');

            return SmOrderResource::make($updated->load([
                'customer',
                'store',
                'coupon',
                'items.product',
                'statusLogs',
                'disputes',
            ]))->additional([
                'message' => 'Order handed to courier successfully.',
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
        $this->context->store((int) $order->store_id);
        $previousStatus = $this->statusValue($order);

        try {
            $data = SmOrderRejectStatusData::from($request->validated());
            $rejectedOrder = $this->orderService->rejectOrder($order, $data);
            $this->activityLogService->logSmOrderRejected((int) $order->id, $order->order_number, (int) $order->store_id);
            $this->notifications->notifyStatusChanged($rejectedOrder, $previousStatus, $this->statusValue($rejectedOrder), 'owner');

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

    private function statusValue(SmOrder $order): string
    {
        return $order->status?->value ?? (string) $order->status;
    }
}
