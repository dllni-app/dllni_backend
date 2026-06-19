<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Exceptions\AuthFlowException;
use Modules\User\Http\Requests\VerifyAccountRequest;
use Modules\User\Services\OtpService;

final class VerifyAccountController
{
    public function __construct(
        public OtpService $otpService,
    ) {}

    public function __invoke(VerifyAccountRequest $request): JsonResponse
    {
        $this->otpService->verify(
            $request->validated('phone'),
            OtpPurpose::Register,
            $request->validated('otp'),
        );

        $user = User::query()->where('phone', $request->validated('phone'))->firstOrFail();
        $user->forceFill(['phone_verified_at' => CarbonImmutable::now()])->save();

        if (! (bool) ($user->is_active ?? true)) {
            throw AuthFlowException::accountNotActive();
        }

        $user->tokens()->where('name', 'user-api')->delete();
        $token = $user->createToken('user-api')->plainTextToken;

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
        ]);
    }
}
