<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Modules\Delivery\Exceptions\MerchantNotReadyException;
use Modules\Delivery\Http\Resources\DeliveryOrderResource;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DriverDispatchService;
use Modules\Delivery\Support\MerchantPreparationPayload;

final class DriverOrderController
{
    public function __construct(
        private readonly DriverDispatchService $dispatchService,
        private readonly DeliveryOrderService $deliveryOrderService,
    ) {}

    public function current(\Illuminate\Http\Request $request): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        $order = $this->dispatchService->currentActiveOrderForDriver($driver);

        return response()->json([
            'data' => $order
                ? DeliveryOrderResource::make($order->load(['assignmentAttempts', 'events']))
                : null,
        ]);
    }

    public function start(\Illuminate\Http\Request $request, DeliveryOrder $order): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        try {
            $updated = $this->deliveryOrderService->start($order, (int) $driver->id);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => DeliveryOrderResource::make($updated->load(['assignmentAttempts', 'events'])),
        ]);
    }

    public function pickup(\Illuminate\Http\Request $request, DeliveryOrder $order): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        try {
            $updated = $this->deliveryOrderService->pickup($order, (int) $driver->id);
        } catch (MerchantNotReadyException $exception) {
            $currentOrder = $order->fresh();

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'merchant_not_ready',
                'merchantPreparation' => MerchantPreparationPayload::forOrder($currentOrder),
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => DeliveryOrderResource::make($updated->load(['assignmentAttempts', 'events'])),
        ]);
    }

    public function deliveryFailed(\Illuminate\Http\Request $request, DeliveryOrder $order): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');
        $validated = $request->validate([
            'reasonCode' => ['required', 'string', 'in:CUSTOMER_UNAVAILABLE,CUSTOMER_REFUSED,WRONG_ADDRESS,PAYMENT_ISSUE,UNSAFE_LOCATION,OTHER'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['reasonCode'] === 'OTHER' && blank($validated['reason'] ?? null)) {
            return response()->json(['message' => 'A reason is required when reasonCode is OTHER.'], 422);
        }

        try {
            $updated = $this->deliveryOrderService->reportDeliveryFailure(
                $order,
                (int) $driver->id,
                (string) $validated['reasonCode'],
                isset($validated['reason']) ? (string) $validated['reason'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => DeliveryOrderResource::make($updated->load(['assignmentAttempts', 'events'])),
        ]);
    }

    public function returnedToMerchant(\Illuminate\Http\Request $request, DeliveryOrder $order): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        try {
            $updated = $this->deliveryOrderService->confirmReturnedToMerchant($order, (int) $driver->id);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => DeliveryOrderResource::make($updated->load(['assignmentAttempts', 'events'])),
        ]);
    }

    public function deliver(\Illuminate\Http\Request $request, DeliveryOrder $order): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        try {
            $updated = $this->deliveryOrderService->deliver($order, (int) $driver->id);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => DeliveryOrderResource::make($updated->load(['assignmentAttempts', 'events'])),
        ]);
    }
}
