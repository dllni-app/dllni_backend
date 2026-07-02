<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\User\Events\RestaurantGroupVoteUpdated;
use Modules\User\Services\RestaurantGroupVoteService;

final class RestaurantGroupVoteEndController
{
    public function __construct(
        private RestaurantGroupVoteService $service,
    ) {}

    public function __invoke(int $vote): JsonResponse
    {
        $model = RestaurantGroupVote::query()->findOrFail($vote);

        $userId = (int) auth()->id();

        $this->service->endNow(
            vote: $model,
            actorUserId: $userId,
        );

        $model->refresh();

        $payload = $this->service->publicPayload($model, $userId);

        BroadcastAfterResponse::send(new RestaurantGroupVoteUpdated($model, $this->service->broadcastRefreshPayload($model)));

        return response()->json([
            'message' => 'Vote ended.',
            'data' => $payload,
        ]);
    }
}
