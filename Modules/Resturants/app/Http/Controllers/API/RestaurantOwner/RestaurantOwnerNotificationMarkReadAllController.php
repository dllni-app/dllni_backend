<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\Response;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerNotificationsReadAllRequest;
use Modules\Resturants\Services\RestaurantOwnerNotificationService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerNotificationMarkReadAllController
{
    public function __invoke(
        OwnerNotificationsReadAllRequest $request,
        RestaurantOwnerContext $context,
        RestaurantOwnerNotificationService $notificationService
    ): Response {
        $notificationService->markAllAsRead(
            $context->owner(),
            $context->restaurant(),
            $request->string('tab')->toString() ?: 'all'
        );

        return response()->noContent();
    }
}
