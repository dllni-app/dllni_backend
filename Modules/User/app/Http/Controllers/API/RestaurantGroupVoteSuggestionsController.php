<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantGroupVoteSuggestionsRequest;
use Modules\User\Services\RestaurantGroupVoteService;

final class RestaurantGroupVoteSuggestionsController
{
    public function __construct(
        private RestaurantGroupVoteService $service,
    ) {}

    public function __invoke(RestaurantGroupVoteSuggestionsRequest $request): JsonResponse
    {
        $payload = $this->service->suggestionsCatalog(
            search: $request->validated('search'),
            cuisineTypeId: $request->validated('cuisineTypeId') !== null ? (int) $request->validated('cuisineTypeId') : null,
            limit: $request->integer('limit', 20),
            foodCategoryId: $request->validated('foodCategoryId') !== null ? (int) $request->validated('foodCategoryId') : null,
            itemTypes: $request->validated('itemTypes', []),
        );

        return response()->json($payload);
    }
}
