<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserCleaningOrderEstimatePriceRequest;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimatePriceController
{
    public function __invoke(
        UserCleaningOrderEstimatePriceRequest $request,
        UserCleaningOrderEstimationService $service,
    ): JsonResponse {
        $validated = $request->validated();

        $estimation = $service->estimate((string) $validated['propertyType'], (array) $validated['propertyDetails']);
        $pricing = $service->price(
            (string) $validated['propertyType'],
            (array) $validated['propertyDetails'],
            $validated['addressLatitude'] ?? null,
            $validated['addressLongitude'] ?? null,
            $validated['preferredWorkerId'] ?? null
        );

        return response()->json([
            'size' => [
                'estimatedSqm' => $estimation['estimatedSqm'],
                'estimatedHours' => $estimation['estimatedHours'],
                'sizeTier' => $estimation['sizeTier'],
            ],
            'pricing' => $pricing,
            'algorithmVersion' => $service->algorithmVersion(),
        ]);
    }
}
