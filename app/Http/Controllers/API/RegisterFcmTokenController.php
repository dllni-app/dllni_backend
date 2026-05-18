<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\NotificationRequests\RegisterFcmTokenRequest;
use Illuminate\Http\JsonResponse;

final class RegisterFcmTokenController
{
    public function __invoke(RegisterFcmTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $token = (string) $request->validated('fcmToken');

        $user->forceFill([
            'fcm_token' => $token,
        ])->save();

        return response()->json([
            'message' => 'FCM token registered successfully.',
            'data' => [
                'module' => 'notifications',
                'tokenRegistered' => true,
                'updatedAt' => $user->fresh()?->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
