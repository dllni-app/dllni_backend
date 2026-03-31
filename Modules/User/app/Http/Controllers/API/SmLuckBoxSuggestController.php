<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\SmLuckBoxSuggestRequest;
use Modules\User\Services\SmLuckBoxService;

final class SmLuckBoxSuggestController
{
    public function __construct(
        private SmLuckBoxService $service,
    ) {}

    public function __invoke(SmLuckBoxSuggestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payload = $this->service->suggest(
            groupSize: (int) $validated['groupSize'],
            budgetPerPerson: (float) $validated['budgetPerPerson'],
            restrictions: $validated['restrictions'] ?? [],
            latitude: isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            longitude: isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            searchRadiusKm: isset($validated['searchRadiusKm']) ? (float) $validated['searchRadiusKm'] : null,
            categoryId: isset($validated['categoryId']) ? (int) $validated['categoryId'] : null,
            storeId: isset($validated['storeId']) ? (int) $validated['storeId'] : null,
        );

        return response()->json($payload);
    }
}
