<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\RestaurantGroupVoteService;

final class RestaurantGroupVoteMyActiveController
{
    public function __construct(
        private RestaurantGroupVoteService $service,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return response()->json([
            'data' => $this->service->activeVotesForUser($userId),
        ]);
    }
}
