<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\User\Http\Requests\RestaurantGroupVoteCastBallotRequest;
use Modules\User\Services\RestaurantGroupVoteService;

final class RestaurantGroupVoteCastBallotController
{
    public function __construct(
        private RestaurantGroupVoteService $service,
    ) {}

    public function __invoke(RestaurantGroupVoteCastBallotRequest $request, int $vote): JsonResponse
    {
        $model = RestaurantGroupVote::query()->findOrFail($vote);

        $this->service->castBallot(
            vote: $model,
            userId: (int) $request->user()->id,
            optionId: (int) $request->validated('optionId'),
        );

        $model->refresh();

        return response()->json([
            'message' => 'Vote recorded.',
            'data' => $this->service->publicPayload($model, (int) $request->user()->id),
        ]);
    }
}
