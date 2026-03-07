<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerOrderItemUpdateRequest;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Services\RestaurantOwnerOrderItemService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerOrderPayload;

final class RestaurantOwnerOrderItemUpdateController
{
    /** @throws ValidationException */
    public function __invoke(
        OwnerOrderItemUpdateRequest $request,
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

        $validated = $request->validated();

        if (array_key_exists('substituteProductId', $validated) && $validated['substituteProductId'] !== null) {
            $substitute = Product::query()->findOrFail((int) $validated['substituteProductId']);
            $context->ensureOwnedProduct($substitute);
        }

        $updatedOrder = $orderItemService->updateItem(
            $order,
            $item,
            isset($validated['quantity']) ? (int) $validated['quantity'] : null,
            $validated['substituteProductId'] ?? null,
            array_key_exists('substituteProductId', $validated),
            $validated['specialInstructions'] ?? null,
            array_key_exists('specialInstructions', $validated)
        );

        $updatedOrder->load([
            'user',
            'restaurant',
            'orderItems.product',
            'orderStatusLogs',
            'promoCode',
            'assignedStaff',
            'disputes',
        ]);

        return response()->json([
            'data' => $payload->build($updatedOrder),
            'message' => 'Order item updated successfully.',
        ]);
    }
}
