<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Services\ActivityLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Data\SmOrderRejectStatusData;
use Modules\Supermarket\Http\Requests\SmOrderAcceptRequest;
use Modules\Supermarket\Http\Requests\SmOrderCancelRequest;
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
     * Accept an order and immediately start Mandoub dispatch.
     */
    public function accept(SmOrderAcceptRequest $request, SmOrder $order): JsonResponse|JsonResource
    {
        $this->context->store((int) $order->store_id);
        $owner = $this->context->owner();
        $previousStatus = $this->statusValue($order);

        try {
            $acceptedOrder = $this->orderService->acceptOrder(
                $order,
                $owner->id,
                $request->filled('preparationTimeMinutes') ? $request->integer('preparationTimeMinutes') : null,
            );
            $acceptedOrder->refresh();

            $this->activityLogService->logSmOrderAccepted((int) $order->id, $order->order_number, (int) $order->store_id);
            $this->notifications->notifyStatusChanged($acceptedOrder, $previousStatus, $this->statusValue($acceptedOrder), 'owner');

            return $this->resource($acceptedOrder, 'Order accepted successfully.');
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Mark accepted order as preparing.
     */
    public function preparing(SmOrder $order): JsonResponse|JsonResource
    {
        $this->context->store((int) $order->store_id);
        $previousStatus = $this->statusValue($order);

        try {
            $updated = $this->orderService->markPreparing($order, $this->context->owner()->id);
            $this->notifications->notifyStatusChanged($updated, $previousStatus, $this->statusValue($updated), 'owner');

            return $this->resource($updated, 'Order marked as preparing successfully.');
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Mark order as ready for pickup without disturbing an assigned driver or active search.
     */
    public function readyForPickup(SmOrder $order): JsonResponse|JsonResource
    {
        $this->context->store((int) $order->store_id);
        $previousStatus = $this->statusValue($order);

        try {
            $updated = $this->orderService->markReadyForPickup($order, $this->context->owner()->id);
            $this->notifications->notifyStatusChanged($updated, $previousStatus, $this->statusValue($updated), 'owner');

            return $this->resource($updated, 'Order marked as ready for pickup successfully.');
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel an accepted/preparing/ready order and release delivery offers or the assigned driver.
     */
    public function cancel(SmOrderCancelRequest $request, SmOrder $order): JsonResponse|JsonResource
    {
        $this->context->store((int) $order->store_id);
        $previousStatus = $this->statusValue($order);

        try {
            $updated = $this->orderService->cancelAfterAcceptance(
                $order,
                (string) $request->validated('reason'),
                $this->context->owner()->id,
            );
            $this->notifications->notifyStatusChanged($updated, $previousStatus, $this->statusValue($updated), 'owner');

            return $this->resource($updated, 'Order cancelled successfully.');
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

            return $this->resource($updated, 'Order handed to courier successfully.');
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reject a pending order with reason and type.
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

            return $this->resource($rejectedOrder, 'Order rejected successfully.');
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    private function resource(SmOrder $order, string $message): JsonResource
    {
        return SmOrderResource::make($order->load([
            'customer',
            'store',
            'coupon',
            'items.product',
            'statusLogs',
            'disputes',
            'deliveryOrder.driver.user',
            'deliveryOrder.driver.latestLocation',
            'deliveryOrder.events',
        ]))->additional([
            'message' => $message,
        ]);
    }

    private function statusValue(SmOrder $order): string
    {
        return $order->status?->value ?? (string) $order->status;
    }
}
