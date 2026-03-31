<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Http\Requests\RegisterRequest;
use Modules\User\Services\OtpService;

final class RegisterController
{
    public function __construct(
        public OtpService $otpService,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => $request->validated('password'),
        ]);

        $expiresAt = $this->otpService->send($user->phone, OtpPurpose::Register);

        return response()->json([
            'message' => 'تم إرسال رمز التحقق.',
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }
}
