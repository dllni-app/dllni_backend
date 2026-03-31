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

        $user = User::query()->where('phone', $phone)->first();

        if ($user) {
            $expiresAt = $this->otpService->send($phone, OtpPurpose::ResetPassword);
        }

        return response()->json([
            'message' => 'إذا كان رقم الهاتف موجودًا، فسيتم إرسال رمز تحقق.',
            'expiresAt' => isset($expiresAt) ? $expiresAt->toIso8601String() : null,
        ]);
    }
}
