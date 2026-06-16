<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Modules\Delivery\Http\Requests\Driver\DriverOfferRejectRequest;
use Modules\Delivery\Http\Resources\DeliveryAssignmentAttemptResource;
use Modules\Delivery\Http\Resources\DeliveryOrderResource;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DriverDispatchService;
use RuntimeException;

final class DriverOfferController
{
    public function __construct(
        private readonly DriverDispatchService $dispatchService,
    ) {}

    public function current(
        \Illuminate\Http\Request $request,
    ): JsonResponse {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        $attempt = $this->dispatchService->currentOpenAttemptForDriver($driver);

        return response()->json([
            'data' => $attempt
                ? DeliveryAssignmentAttemptResource::make($attempt->load('order'))
                : null,
        ]);
    }

    public function accept(\Illuminate\Http\Request $request, int $attempt): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        try {
            $order = $this->dispatchService->acceptAttempt($attempt, $driver);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'data' => DeliveryOrderResource::make($order->load(['assignmentAttempts', 'events'])),
        ]);
    }

    public function reject(DriverOfferRejectRequest $request, int $attempt): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        try {
            $this->dispatchService->rejectAttempt(
                attemptId: $attempt,
                driver: $driver,
                reason: (string) $request->validated('reason'),
            );
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'data' => [
                'ok' => true,
            ],
        ]);
    }
}
