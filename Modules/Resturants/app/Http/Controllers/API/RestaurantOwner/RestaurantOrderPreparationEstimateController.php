<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Delivery\Http\Requests\MerchantPreparationEstimateRequest;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Services\OrderService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerOrderPayload;

final class RestaurantOrderPreparationEstimateController
{
    public function __invoke(
        MerchantPreparationEstimateRequest $request,
        Order $order,
        RestaurantOwnerContext $context,
        RestaurantOwnerOrderPayload $payload,
        OrderService $orders,
    ): JsonResponse {
        $context->ensureOwnedOrder($order);
        $updated = $orders->updatePreparationEstimate($order, $request->integer('preparationTimeMinutes') ?: null);
        $updated->load([
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
            'data' => $payload->build($updated),
            'message' => 'Preparation estimate updated successfully.',
        ]);
    }
}
