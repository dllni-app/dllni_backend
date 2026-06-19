<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Exceptions\AuthFlowException;
use Modules\User\Http\Requests\RegisterRequest;
use Modules\User\Services\OtpService;
use Modules\User\Services\RegisterUserService;
use Symfony\Component\HttpFoundation\Response;

final class RegisterController
{
    public function __construct(
        private readonly RegisterUserService $registerUserService,
        private readonly OtpService $otpService,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $existingUser = User::query()->where('phone', $phone)->first();

        if ($existingUser instanceof User) {
            if (! (bool) ($existingUser->is_active ?? true)) {
                throw AuthFlowException::accountNotActive();
            }

            if ($existingUser->phone_verified_at !== null) {
                throw AuthFlowException::userAlreadyRegistered((string) $existingUser->phone);
            }

            $expiresAt = $this->otpService->send((string) $existingUser->phone, OtpPurpose::Register);

            throw AuthFlowException::phoneVerificationRequired(
                (string) $existingUser->phone,
                [
                    'next_action' => 'verify_phone',
                    'otp_sent' => true,
                    'expiresAt' => $expiresAt->toIso8601String(),
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $expiresAt = $this->registerUserService->register($request->validated());

        return response()->json([
            'message' => 'تم إرسال رمز التحقق.',
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }
}
