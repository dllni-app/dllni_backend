<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserAccountShowController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()?->fresh(['media']);

        return response()->json([
            'user' => UserResource::make($user),
            'unreadNotificationsCount' => $user ? $user->unreadNotifications()->count() : 0,
        ]);
    }
}
