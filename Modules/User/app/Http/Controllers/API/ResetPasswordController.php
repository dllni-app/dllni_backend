<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Http\Requests\ResetPasswordRequest;
use Modules\User\Services\OtpService;

final class ResetPasswordController
{
    public function __construct(
        public OtpService $otpService,
    ) {}

    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $expiresAt = null;
        $code = 'PASSWORD_RESET_OTP_SENT_IF_ACCOUNT_EXISTS';
        $message = "\u{0625}\u{0630}\u{0627} \u{0643}\u{0627}\u{0646} \u{0631}\u{0642}\u{0645} \u{0627}\u{0644}\u{0647}\u{0627}\u{062A}\u{0641} \u{0645}\u{0633}\u{062C}\u{0644}\u{0627}\u{064B} \u{0644}\u{062F}\u{064A}\u{0646}\u{0627}\u{060C} \u{0633}\u{064A}\u{062A}\u{0645} \u{0625}\u{0631}\u{0633}\u{0627}\u{0644} \u{0631}\u{0645}\u{0632} \u{0625}\u{0639}\u{0627}\u{062F}\u{0629} \u{062A}\u{0639}\u{064A}\u{064A}\u{0646} \u{0643}\u{0644}\u{0645}\u{0629} \u{0627}\u{0644}\u{0645}\u{0631}\u{0648}\u{0631}.";

        $user = User::query()->where('phone', $phone)->first();

        if ($user) {
            $expiresAt = $this->otpService->send($phone, OtpPurpose::ResetPassword);
            $code = 'PASSWORD_RESET_OTP_SENT';
            $message = "\u{062A}\u{0645} \u{0625}\u{0631}\u{0633}\u{0627}\u{0644} \u{0631}\u{0645}\u{0632} \u{0625}\u{0639}\u{0627}\u{062F}\u{0629} \u{062A}\u{0639}\u{064A}\u{064A}\u{0646} \u{0643}\u{0644}\u{0645}\u{0629} \u{0627}\u{0644}\u{0645}\u{0631}\u{0648}\u{0631} \u{0625}\u{0644}\u{0649} \u{0631}\u{0642}\u{0645} \u{0627}\u{0644}\u{0647}\u{0627}\u{062A}\u{0641}.";
        }

        return response()->json([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => [
                'phone' => $phone,
                'next_action' => 'verify_reset_otp',
            ],
            'expiresAt' => $expiresAt?->toIso8601String(),
        ]);
    }
}
