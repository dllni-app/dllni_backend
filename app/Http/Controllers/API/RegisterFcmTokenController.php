<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\NotificationRequests\RegisterFcmTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class RegisterFcmTokenController
{
    public function __invoke(RegisterFcmTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $token = (string) $request->validated('fcmToken');

        $this->claimTokenForUser($user, $token);

        return response()->json([
            'message' => 'FCM token registered successfully.',
            'data' => [
                'module' => 'notifications',
                'tokenRegistered' => true,
                'updatedAt' => $user->fresh()?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    private function claimTokenForUser(User $user, string $token): void
    {
        User::query()
            ->where('fcm_token', $token)
            ->whereKeyNot($user->getKey())
            ->update(['fcm_token' => null]);

        if (($user->getAttributes()['fcm_token'] ?? null) !== $token) {
            $user->forceFill([
                'fcm_token' => $token,
            ])->save();
        }
    }
}
