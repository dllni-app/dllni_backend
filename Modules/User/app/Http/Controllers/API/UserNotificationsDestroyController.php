<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Controllers\API\UserNotificationController;
use Illuminate\Http\Response;

final class UserNotificationsDestroyController
{
    public function __invoke(string $id, UserNotificationController $notifications): Response
    {
        return $notifications->destroy($id);
    }
}
