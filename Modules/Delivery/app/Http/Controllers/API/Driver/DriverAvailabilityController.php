<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use Illuminate\Http\JsonResponse;
use Modules\Delivery\Http\Requests\Driver\DriverAvailabilityRequest;
use Modules\Delivery\Http\Resources\DeliveryDriverResource;

final class DriverAvailabilityController
{
    public function __invoke(DriverAvailabilityRequest $request): JsonResponse
    {
        $driver = $request->attributes->get('deliveryDriver');
        $driver->forceFill(['availability_status' => $request->validated('availabilityStatus')])->save();

        return response()->json(['data' => DeliveryDriverResource::make($driver->fresh())]);
    }
}
