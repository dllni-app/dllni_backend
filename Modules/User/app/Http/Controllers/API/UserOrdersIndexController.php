<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserOrdersIndexRequest;
use Modules\User\Services\UserOrderHubService;

final class UserOrdersIndexController
{
    public function __construct(
        private readonly UserOrderHubService $orders,
    ) {}

    public function __invoke(UserOrdersIndexRequest $request): JsonResponse
    {
        $result = $this->orders->list(
            userId: (int) $request->user()->id,
            section: (string) $request->input('section', 'all'),
            status: $request->input('status'),
            search: $request->input('search'),
            restaurantId: $request->filled('restaurantId') ? (int) $request->integer('restaurantId') : null,
            perPage: (int) $request->integer('perPage', 20),
            page: (int) $request->integer('page', 1),
        );

        return response()->json($result);
    }
}
