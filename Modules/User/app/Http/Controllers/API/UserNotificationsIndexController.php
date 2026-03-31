<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Controllers\API\UserNotificationController;
use App\Http\Requests\UserNotificationRequests\UserNotificationIndexRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserNotificationsIndexController
{
    public function __invoke(
        UserNotificationIndexRequest $request,
        UserNotificationController $notifications,
    ): AnonymousResourceCollection {
        return $notifications->index($request);
    }
}
