<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerOrderPayload;

final class RestaurantOwnerOrderShowController
{
    public function __invoke(
        Order $order,
        RestaurantOwnerContext $context,
        RestaurantOwnerOrderPayload $payload
    ): JsonResponse {
        $context->ensureOwnedOrder($order);

        $order->load([
            'user',
            'restaurant',
            'orderItems.product',
            'orderStatusLogs',
            'promoCode',
            'assignedStaff',
            'disputes',
        ]);

        return response()->json([
            'data' => $payload->build($order),
        ]);
    }
}
