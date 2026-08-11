<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\User\Services\RestaurantGroupVoteService;

final class RestaurantGroupVoteShowController
{
    public function __construct(
        private RestaurantGroupVoteService $service,
    ) {}

    public function __invoke(Request $request, int $vote): JsonResponse
    {
        $model = RestaurantGroupVote::query()->findOrFail($vote);
        $currentUser = $request->user('sanctum') ?? $request->user();
        $currentUserId = $currentUser?->id;

        return response()->json([
            'data' => $this->service->publicPayload($model, $currentUserId !== null ? (int) $currentUserId : null),
        ]);
    }
}
