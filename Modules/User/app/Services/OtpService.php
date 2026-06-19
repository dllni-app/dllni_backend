<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Modules\User\Data\OtpIssueData;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Exceptions\AuthFlowException;
use Modules\User\Models\UserOtp;
use Modules\User\Services\Sms\SmsMessageBuilder;

final class OtpService
{
    public function __construct(
        private readonly SmsMessageBuilder $smsMessageBuilder,
    ) {}

    public function send(string $phone, OtpPurpose $purpose): CarbonImmutable
    {
        $issued = $this->issue($phone, $purpose);
        $this->queueSms($phone, $issued->code);

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
            ->orderByDesc('id')
            ->first();

        if (! $otp) {
            throw AuthFlowException::otpInvalid();
        }

        if ($otp->expires_at->isPast()) {
            throw AuthFlowException::otpExpired();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->attempts >= 5) {
                $otp->update(['consumed_at' => CarbonImmutable::now()]);
            }

            throw AuthFlowException::otpInvalid();
        }

        $otp->update(['consumed_at' => CarbonImmutable::now()]);
    }

    private function queueSms(string $phone, string $code): void
    {
        $user = User::query()->where('phone', $phone)->first();

        if (! $user instanceof User) {
            return;
        }

        $smsPayload = $this->smsMessageBuilder->registrationOtp(
            otp: $code,
            locale: app()->getLocale(),
        );

        $smsMessage = $user->smsMessages()->create([
            'provider' => 'mtn',
            'gsm' => $this->normalizeGsm($phone),
            'message' => $smsPayload['message'],
            'lang' => $smsPayload['lang'],
            'status' => 'pending',
            'attempts_count' => 0,
        ]);

        SendRegistrationSmsJob::dispatch($smsMessage->id);
    }

    private function normalizeGsm(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = ltrim($digits, '0');
        }

        if (str_starts_with($digits, '09')) {
            return '963'.substr($digits, 1);
        }

        return $digits;
    }

    private function plainOtpCacheKey(string $phone, OtpPurpose $purpose): string
    {
        return sprintf('user_otp_plain:%s:%s', $purpose->value, $phone);
    }
}
