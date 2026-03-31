<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Http\Requests\LoginRequest;
use Modules\User\Services\OtpService;

final class LoginController
{
    public function __construct(
        public OtpService $otpService,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->firstOrFail();

        if (! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'phone' => [__('auth.failed')],
            ]);
        }

        $expiresAt = $this->otpService->send($user->phone, OtpPurpose::Login);

        return response()->json([
            'message' => 'تم إرسال رمز التحقق.',
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }
}
