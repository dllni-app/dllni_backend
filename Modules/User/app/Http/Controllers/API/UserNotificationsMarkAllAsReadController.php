<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Controllers\API\UserNotificationController;
use Illuminate\Http\Response;

final class UserNotificationsMarkAllAsReadController
{
    public function __invoke(UserNotificationController $notifications): Response
    {
        return $notifications->markAllAsRead();
    }
}
