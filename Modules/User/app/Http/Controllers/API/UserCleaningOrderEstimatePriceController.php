<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\User\Http\Requests\UserCleaningOrderEstimatePriceRequest;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimatePriceController
{
    public function __invoke(
        UserCleaningOrderEstimatePriceRequest $request,
        UserCleaningOrderEstimationService $service,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $estimation = $service->estimate(
                (string) $validated['propertyType'],
                (array) $validated['propertyDetails'],
                isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
            );
            $pricing = $service->price(
                (string) $validated['propertyType'],
                (array) $validated['propertyDetails'],
                $validated['addressLatitude'] ?? null,
                $validated['addressLongitude'] ?? null,
                $validated['preferredWorkerId'] ?? null,
                isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'pricing' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'size' => [
                'estimatedSqm' => $estimation['estimatedSqm'],
                'estimatedHours' => $estimation['estimatedHours'],
                'sizeTier' => $estimation['sizeTier'],
            ],
            'pricing' => $pricing,
            'recommendation' => $estimation['recommendation'] ?? null,
            'algorithmVersion' => $service->algorithmVersion(),
        ]);
    }
}
