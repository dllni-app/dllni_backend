<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Http\Requests\LoginVerifyRequest;
use Modules\User\Services\OtpService;

final class LoginVerifyController
{
    public function __construct(
        public OtpService $otpService,
    ) {}

    public function __invoke(LoginVerifyRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->firstOrFail();

        if (! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'phone' => [__('auth.failed')],
            ]);
        }

        $this->otpService->verify(
            $request->validated('phone'),
            OtpPurpose::Login,
            $request->validated('otp'),
        );

        $fcmToken = $request->validated('fcmToken');
        if (is_string($fcmToken) && $fcmToken !== '') {
            $user->forceFill([
                'fcm_token' => $fcmToken,
            ])->save();
        }

        $user->tokens()->where('name', 'user-api')->delete();
        $token = $user->createToken('user-api')->plainTextToken;

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
        ]);
    }
}
