<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\User\Http\Requests\RestaurantGroupVoteInviteUsersRequest;
use Modules\User\Services\RestaurantGroupVoteService;

final class RestaurantGroupVoteInviteUsersController
{
    public function __construct(
        private RestaurantGroupVoteService $service,
    ) {}

    public function __invoke(RestaurantGroupVoteInviteUsersRequest $request, int $vote): JsonResponse
    {
        $model = RestaurantGroupVote::query()->findOrFail($vote);
        $currentUserId = (int) $request->user()->id;

        /** @var list<int> $userIds */
        $userIds = array_map(
            fn (mixed $id): int => (int) $id,
            $request->validated('userIds')
        );

        $invitedUserIds = $this->service->inviteUsers(
            vote: $model,
            actorUserId: $currentUserId,
            userIds: $userIds,
        );

        $model->refresh();

        return response()->json([
            'message' => 'Users invited.',
            'data' => [
                'vote' => $this->service->publicPayload($model, $currentUserId)['vote'],
                'invitedUserIds' => $invitedUserIds,
            ],
        ]);
    }
}
