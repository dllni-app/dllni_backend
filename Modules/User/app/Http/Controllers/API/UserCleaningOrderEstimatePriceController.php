<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserCleaningOrderEstimatePriceRequest;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderQuoteService;

final class UserCleaningOrderEstimatePriceController
{
    public function __invoke(
        UserCleaningOrderEstimatePriceRequest $request,
        UserCleaningOrderEstimationService $service,
        UserCleaningOrderQuoteService $quoteService,
    ): JsonResponse {
        $validated = $request->validated();

        $normalizedInput = $service->pricingSnapshotInput(
            (string) $validated['propertyType'],
            (array) $validated['propertyDetails'],
            $validated['addressLatitude'] ?? null,
            $validated['addressLongitude'] ?? null,
            $validated['preferredWorkerId'] ?? null
        );

        $estimation = $service->estimate((string) $validated['propertyType'], (array) $validated['propertyDetails']);
        $pricing = $service->price(
            (string) $validated['propertyType'],
            (array) $validated['propertyDetails'],
            $validated['addressLatitude'] ?? null,
            $validated['addressLongitude'] ?? null,
            $validated['preferredWorkerId'] ?? null
        );
        $quote = $quoteService->issueQuote($request->user(), $normalizedInput, $estimation, $pricing);

        return response()->json([
            'size' => [
                'estimatedSqm' => $estimation['estimatedSqm'],
                'estimatedHours' => $estimation['estimatedHours'],
                'sizeTier' => $estimation['sizeTier'],
            ],
            'pricing' => $pricing,
            'quote' => $quote,
        ]);
    }
}
