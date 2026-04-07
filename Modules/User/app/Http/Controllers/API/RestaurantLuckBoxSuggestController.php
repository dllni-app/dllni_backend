<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantLuckBoxSuggestRequest;
use Modules\User\Services\RestaurantLuckBoxService;

final class RestaurantLuckBoxSuggestController
{
    public function __construct(
        private RestaurantLuckBoxService $service,
    ) {}

    public function __invoke(RestaurantLuckBoxSuggestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payload = $this->service->suggest(
            groupSize: (int) $validated['groupSize'],
            budgetPerPerson: (float) $validated['budgetPerPerson'],
            restrictions: $validated['restrictions'] ?? [],
            latitude: isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            longitude: isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            cuisineTypeId: isset($validated['cuisineTypeId']) ? (int) $validated['cuisineTypeId'] : null,
            restaurantId: isset($validated['restaurantId']) ? (int) $validated['restaurantId'] : null,
        );

        return response()->json($payload);
    }
}
