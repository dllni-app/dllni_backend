<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use App\Jobs\DispatchAvailablePlatformCouponNotificationsToUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
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
        $user = User::query()->where('phone', $request->validated('phone'))->firstOrFail();
        if ($user->module_type !== null) {
            throw ValidationException::withMessages([
                'phone' => ['هذا الحساب مخصص لتطبيق آخر ولا يمكن استخدامه في تطبيق العميل.'],
            ]);
        }

        $this->otpService->verify(
            $request->validated('phone'),
            OtpPurpose::Register,
            $request->validated('otp'),
        );
        $wasUnverified = $user->phone_verified_at === null;
        $user->forceFill(['phone_verified_at' => CarbonImmutable::now()])->save();

        if (! (bool) ($user->is_active ?? true)) {
            throw AuthFlowException::accountNotActive();
        }

        $user->tokens()->where('name', 'user-api')->delete();
        $token = $user->createToken('user-api')->plainTextToken;

        if ($wasUnverified) {
            DispatchAvailablePlatformCouponNotificationsToUser::dispatch((int) $user->id)->afterCommit();
        }

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
        ]);
    }
}
