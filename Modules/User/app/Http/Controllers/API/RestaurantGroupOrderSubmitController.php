<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderSubmitController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(int $groupOrder): JsonResponse
    {
        $model = RestaurantGroupOrder::query()->findOrFail($groupOrder);
        $userId = (int) Auth::id();

        $this->service->setParticipantSubmission(
            groupOrder: $model,
            userId: $userId,
            submitted: true,
        );

        $model->refresh();
        $payload = $this->service->publicPayload($model, $userId);
        BroadcastAfterResponse::send(new RestaurantGroupOrderUpdated($model, $payload));

        return response()->json([
            'message' => 'Participation confirmed.',
            'data' => $payload,
        ]);
    }
}
