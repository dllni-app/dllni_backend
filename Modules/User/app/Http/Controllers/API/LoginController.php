<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\User\Http\Requests\LoginRequest;

final class LoginController
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->firstOrFail();

        if (! Hash::check($request->validated('password'), $user->password)) {

            throw ValidationException::withMessages([
                'phone' => [__('auth.failed')],
            ]);
        }

        $fcmToken = $request->validated('fcmToken');
        if (is_string($fcmToken) && $fcmToken !== '') {
            $user->forceFill([
                'fcm_token' => $fcmToken,
            ])->save();
        }

        return response()->json([
            'data' => UserResource::make($user->load(['media', 'worker'])),
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }
}
