<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Modules\Delivery\Http\Requests\MerchantPreparationEstimateRequest;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\SmOrderService;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class SmOrderPreparationEstimateController
{
    public function __invoke(
        MerchantPreparationEstimateRequest $request,
        SmOrder $order,
        StoreOwnerContextService $context,
        SmOrderService $orders,
    ): JsonResponse {
        $context->store((int) $order->store_id);
        $updated = $orders->updatePreparationEstimate($order, $request->integer('preparationTimeMinutes') ?: null);

        return response()->json([
            'data' => SmOrderResource::make($updated->load(['customer', 'store', 'items.product', 'statusLogs', 'deliveryOrder.events'])),
            'message' => 'Preparation estimate updated successfully.',
        ]);
    }
}
