<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\User\Data\OtpIssueData;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Models\UserOtp;
use Modules\User\Services\SmsOtp\SmsOtpProvider;

final class OtpService
{
    public function __construct(
        public SmsOtpProvider $provider,
    ) {}

    public function send(string $phone, OtpPurpose $purpose): CarbonImmutable
    {
        $issued = $this->issue($phone, $purpose);

        $this->provider->sendOtp($phone, $issued->code, $purpose);

        return $issued->expiresAt;
    }

    public function issue(string $phone, OtpPurpose $purpose): OtpIssueData
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = CarbonImmutable::now()->addMinutes(5);

        UserOtp::create([
            'phone' => $phone,
            'purpose' => $purpose->value,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
        ]);

        if (app()->environment(['local', 'testing'])) {
            Cache::put($this->plainOtpCacheKey($phone, $purpose), $code, $expiresAt);
        }

        return new OtpIssueData(
            code: $code,
            expiresAt: $expiresAt,
        );
    }

    public function verify(string $phone, OtpPurpose $purpose, string $code): void
    {
        $otp = UserOtp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->orderByDesc('id')
            ->first();

        if (! $otp) {
            throw ValidationException::withMessages([
                'otp' => ['رمز التحقق غير صحيح أو منتهي الصلاحية.'],
            ]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->attempts >= 5) {
                $otp->update(['consumed_at' => CarbonImmutable::now()]);
            }

            throw ValidationException::withMessages([
                'otp' => ['رمز التحقق غير صحيح أو منتهي الصلاحية.'],
            ]);
        }

        $otp->update(['consumed_at' => CarbonImmutable::now()]);
    }

    private function plainOtpCacheKey(string $phone, OtpPurpose $purpose): string
    {
        return sprintf('user_otp_plain:%s:%s', $purpose->value, $phone);
    }
}
