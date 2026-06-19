<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Exceptions\AuthFlowException;
use Modules\User\Http\Requests\LoginRequest;
use Modules\User\Services\OtpService;

final class LoginController
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user instanceof User || ! Hash::check($request->validated('password'), $user->password)) {
            throw AuthFlowException::invalidCredentials();
        }

        if (! $this->isActive($user)) {
            throw AuthFlowException::accountNotActive();
        }

        if ($user->phone_verified_at === null) {
            $expiresAt = $this->otpService->send((string) $user->phone, OtpPurpose::Register);

            throw AuthFlowException::phoneVerificationRequired(
                (string) $user->phone,
                [
                    'next_action' => 'verify_phone',
                    'otp_sent' => true,
                    'expiresAt' => $expiresAt->toIso8601String(),
                ],
            );
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
