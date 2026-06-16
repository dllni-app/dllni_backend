<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use Illuminate\Http\JsonResponse;
use Modules\Delivery\Http\Requests\Driver\DriverLocationRequest;
use Modules\Delivery\Http\Resources\DeliveryDriverLocationResource;
use Modules\Delivery\Services\DriverLocationService;

final class DriverLocationController
{
    public function __construct(private readonly DriverLocationService $locationService) {}

    public function __invoke(DriverLocationRequest $request): JsonResponse
    {
        $driver = $request->attributes->get('deliveryDriver');
        $validated = $request->validated();
        $location = $this->locationService->appendLocation(
            $driver,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
            isset($validated['speed']) ? (float) $validated['speed'] : null,
            isset($validated['heading']) ? (float) $validated['heading'] : null,
        );

        return response()->json(['data' => DeliveryDriverLocationResource::make($location)], 201);
    }
}
