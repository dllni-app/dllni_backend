<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantGroupVoteStoreRequest;
use Modules\User\Services\RestaurantGroupVoteService;

final class RestaurantGroupVoteStoreController
{
    public function __construct(
        private RestaurantGroupVoteService $service,
    ) {}

    public function __invoke(RestaurantGroupVoteStoreRequest $request): JsonResponse
    {
        /** @var list<array{label: string, productId?: int|null}> $options */
        $options = array_map(
            fn (array $row): array => [
                'label' => (string) $row['label'],
                'productId' => isset($row['productId']) ? (int) $row['productId'] : null,
            ],
            $request->validated('options')
        );

        $vote = $this->service->create(
            creatorUserId: (int) $request->user()->id,
            durationMinutes: (int) $request->validated('durationMinutes'),
            options: $options,
            foodCategoryHint: $request->validated('foodCategoryHint'),
            cuisineTypeId: $request->validated('cuisineTypeId') !== null ? (int) $request->validated('cuisineTypeId') : null,
        );

        return response()->json([
            'message' => 'Vote created.',
            'data' => $this->service->publicPayload($vote, (int) $request->user()->id),
        ], 201);
    }
}
