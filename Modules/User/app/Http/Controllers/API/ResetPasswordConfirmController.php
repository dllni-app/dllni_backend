<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Http\Requests\ResetPasswordConfirmRequest;
use Modules\User\Services\OtpService;

final class ResetPasswordConfirmController
{
    public function __construct(
        public OtpService $otpService,
    ) {}

    public function __invoke(ResetPasswordConfirmRequest $request): JsonResponse
    {
        $this->otpService->verify(
            $request->validated('phone'),
            OtpPurpose::ResetPassword,
            $request->validated('otp'),
        );

        $user = User::query()->where('phone', $request->validated('phone'))->firstOrFail();
        $user->forceFill(['password' => $request->validated('password')])->save();

        $user->tokens()->where('name', 'user-api')->delete();
        $token = $user->createToken('user-api')->plainTextToken;

        return response()->json([
            'message' => 'تم إعادة تعيين كلمة المرور.',
            'token' => $token,
        ]);
    }
}
