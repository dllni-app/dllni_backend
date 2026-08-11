<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Exceptions\AuthFlowException;
use Modules\User\Http\Requests\ResendAccountVerificationOtpRequest;
use Modules\User\Services\OtpService;

final class ResendAccountVerificationOtpController
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function __invoke(ResendAccountVerificationOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $user = User::query()->where('phone', $phone)->firstOrFail();

        if (! (bool) ($user->is_active ?? true)) {
            throw AuthFlowException::accountNotActive();
        }

        if ($user->phone_verified_at !== null) {
            throw AuthFlowException::userAlreadyRegistered((string) $user->phone);
        }

        $expiresAt = $this->otpService->send((string) $user->phone, OtpPurpose::Register);

        return response()->json([
            'success' => true,
            'code' => 'PHONE_VERIFICATION_OTP_SENT',
            'message' => "\u{062A}\u{0645} \u{0627}\u{0633}\u{062A}\u{0644}\u{0627}\u{0645} \u{0637}\u{0644}\u{0628}\u{0643} \u{0628}\u{0646}\u{062C}\u{0627}\u{062D}",
            'data' => [
                'phone' => $user->phone,
                'next_action' => 'verify_phone',
            ],
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }
}
