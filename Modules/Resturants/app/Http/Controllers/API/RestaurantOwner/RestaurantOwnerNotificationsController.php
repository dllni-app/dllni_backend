<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerNotificationsIndexRequest;
use Modules\Resturants\Services\RestaurantOwnerNotificationService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerNotificationsController
{
    public function __invoke(
        OwnerNotificationsIndexRequest $request,
        RestaurantOwnerContext $context,
        RestaurantOwnerNotificationService $notificationService
    ): JsonResponse {
        $owner = $context->owner();
        $restaurant = $context->restaurant();

        return response()->json(
            $notificationService->feed(
                $owner,
                $restaurant,
                $request->string('tab')->toString() ?: 'all',
                (bool) $request->boolean('unreadOnly'),
                (int) $request->integer('perPage', 15),
                (int) $request->integer('page', 1),
            )
        );
    }
}
