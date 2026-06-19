<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Exceptions\AuthFlowException;
use Modules\User\Http\Requests\LoginVerifyRequest;
use Modules\User\Services\OtpService;

final class LoginVerifyController
{
    public function __construct(
        public OtpService $otpService,
    ) {}

    public function __invoke(LoginVerifyRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user instanceof User || ! Hash::check($request->validated('password'), $user->password)) {
            throw AuthFlowException::invalidCredentials();
        }

        if (! $this->isActive($user)) {
            throw AuthFlowException::accountNotActive();
        }

        if ($user->phone_verified_at === null) {
            throw AuthFlowException::phoneVerificationRequired((string) $user->phone);
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
            'user' => UserResource::make($user->load('worker')),
            'token' => $token,
        ]);
    }

    private function isActive(User $user): bool
    {
        $attributes = $user->getAttributes();

        if (array_key_exists('is_active', $attributes)) {
            return (bool) $attributes['is_active'];
        }

        if (array_key_exists('is_activated', $attributes)) {
            return (bool) $attributes['is_activated'];
        }

        if (array_key_exists('active', $attributes)) {
            return (bool) $attributes['active'];
        }

        $status = $attributes['status'] ?? $attributes['account_status'] ?? null;

        if (is_string($status)) {
            return in_array(strtolower($status), ['active', 'activated', 'enabled'], true);
        }

        return true;
    }
}
