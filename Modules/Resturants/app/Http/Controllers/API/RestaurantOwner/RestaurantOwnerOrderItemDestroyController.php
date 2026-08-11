<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Services\RestaurantOwnerOrderItemService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerOrderPayload;

final class RestaurantOwnerOrderItemDestroyController
{
    /** @throws ValidationException */
    public function __invoke(
        Order $order,
        OrderItem $item,
        RestaurantOwnerContext $context,
        RestaurantOwnerOrderItemService $orderItemService,
        RestaurantOwnerOrderPayload $payload
    ): JsonResponse {
        $context->ensureOwnedOrder($order);

        if ((int) $item->order_id !== (int) $order->id) {
            throw ValidationException::withMessages([
                'item' => 'Order item does not belong to this order.',
            ]);
        }

        if (! $payload->canEdit($order)) {
            throw ValidationException::withMessages([
                'order' => 'Order items can only be edited while order is pending or accepted.',
            ]);
        }

        $updatedOrder = $orderItemService->removeItem($order, $item);
        $updatedOrder->load([
            'user.addresses',
            'userAddress',
            'restaurant',
            'orderItems.product',
            'orderStatusLogs',
            'promoCode',
            'assignedStaff',
            'disputes',
        ]);

        return response()->json([
            'data' => $payload->build($updatedOrder),
            'message' => 'Order item removed successfully.',
        ]);
    }
}
