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

        // Keep the recommendation engine free to compose bundles internally,
        // while presenting Lucky Box to mobile users as product suggestions.
        $productSuggestions = [];
        foreach (($payload['bundles'] ?? []) as $bundle) {
            foreach (($bundle['lineItems'] ?? []) as $lineItem) {
                if (! is_array($lineItem) || empty($lineItem['productId'])) {
                    continue;
                }

                $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
                $unitPrice = max(0.0, (float) ($lineItem['unitPrice'] ?? 0));
                $lineTotal = max(0.0, (float) ($lineItem['lineTotal'] ?? ($unitPrice * $quantity)));

                $suggestion = $bundle;
                $suggestion['totalProducts'] = $quantity;
                $suggestion['itemsDescription'] = (string) ($lineItem['name'] ?? 'منتج مقترح');
                $suggestion['totalPrice'] = round($lineTotal, 2);
                $suggestion['lineItems'] = [$lineItem];
                $suggestion['productSuggestion'] = true;
                $suggestion['productId'] = (int) $lineItem['productId'];
                $suggestion['productName'] = (string) ($lineItem['name'] ?? '');
                $suggestion['productImageUrl'] = $lineItem['imageUrl'] ?? null;

                if (is_array($suggestion['restaurant'] ?? null) && ! empty($lineItem['imageUrl'])) {
                    // Existing mobile models already read this field for the
                    // first-level card; use the actual product image there.
                    $suggestion['restaurant']['primaryImageUrl'] = $lineItem['imageUrl'];
                }

                $productSuggestions[] = $suggestion;
            }
        }

        $payload['bundles'] = $productSuggestions;

        return response()->json($payload);
    }
}
