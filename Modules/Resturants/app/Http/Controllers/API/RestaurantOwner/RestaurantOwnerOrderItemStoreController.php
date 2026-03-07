<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerOrderItemStoreRequest;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Services\RestaurantOwnerOrderItemService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerOrderPayload;

final class RestaurantOwnerOrderItemStoreController
{
    /** @throws ValidationException */
    public function __invoke(
        OwnerOrderItemStoreRequest $request,
        Order $order,
        RestaurantOwnerContext $context,
        RestaurantOwnerOrderItemService $orderItemService,
        RestaurantOwnerOrderPayload $payload
    ): JsonResponse {
        $context->ensureOwnedOrder($order);

        if (! $payload->canEdit($order)) {
            throw ValidationException::withMessages([
                'order' => 'Order items can only be edited while order is pending or accepted.',
            ]);
        }

        $validated = $request->validated();
        $product = Product::query()->findOrFail((int) $validated['productId']);
        $context->ensureOwnedProduct($product);

        if (isset($validated['substituteProductId'])) {
            $substitute = Product::query()->findOrFail((int) $validated['substituteProductId']);
            $context->ensureOwnedProduct($substitute);
        }

        $updatedOrder = $orderItemService->addItem(
            $order,
            $product,
            (int) $validated['quantity'],
            isset($validated['substituteProductId']) ? (int) $validated['substituteProductId'] : null,
            $validated['specialInstructions'] ?? null
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
            'message' => 'Order item added successfully.',
        ]);
    }
}
