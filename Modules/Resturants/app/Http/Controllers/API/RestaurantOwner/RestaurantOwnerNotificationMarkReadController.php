<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\Response;
use Modules\Resturants\Services\RestaurantOwnerNotificationService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerNotificationMarkReadController
{
    public function __invoke(
        string $notification,
        RestaurantOwnerContext $context,
        RestaurantOwnerNotificationService $notificationService
    ): Response {
        $notificationService->markAsRead(
            $context->owner(),
            $context->restaurant(),
            $notification
        );

        return response()->noContent();
    }
}
