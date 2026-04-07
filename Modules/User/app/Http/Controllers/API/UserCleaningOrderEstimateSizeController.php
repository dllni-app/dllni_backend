<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserCleaningOrderEstimateSizeRequest;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimateSizeController
{
    public function __invoke(UserCleaningOrderEstimateSizeRequest $request, UserCleaningOrderEstimationService $service): JsonResponse
    {
        $validated = $request->validated();
        $estimation = $service->estimate((string) $validated['propertyType'], (array) $validated['propertyDetails']);

        return response()->json([
            'size' => [
                'estimatedSqm' => $estimation['estimatedSqm'],
                'sizeTier' => $estimation['sizeTier'],
            ],
            'estimation' => [
                'estimatedHours' => $estimation['estimatedHours'],
                'estimatedMinutes' => (int) round($estimation['estimatedHours'] * 60),
            ],
        ]);
    }
}
