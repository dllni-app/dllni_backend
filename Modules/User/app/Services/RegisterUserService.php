<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Services\Sms\SmsMessageBuilder;

final class RegisterUserService
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly SmsMessageBuilder $smsMessageBuilder,
    ) {}

    public function register(array $data): CarbonImmutable
    {
        return DB::transaction(function () use ($data): CarbonImmutable {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
            ]);

            $otp = $this->otpService->issue($user->phone, OtpPurpose::Register);
            $smsPayload = $this->smsMessageBuilder->registrationOtp(
                otp: $otp->code,
                locale: app()->getLocale(),
            );

            $smsMessage = $user->smsMessages()->create([
                'provider' => 'mtn',
                'gsm' => $this->normalizeGsm($user->phone),
                'message' => $smsPayload['message'],
                'lang' => $smsPayload['lang'],
                'status' => 'pending',
                'attempts_count' => 0,
            ]);

            SendRegistrationSmsJob::dispatch($smsMessage->id);

            return $otp->expiresAt;
        });
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
}
