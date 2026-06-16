<?php

declare(strict_types=1);

namespace Modules\User\Services\Sms;

final class SmsMessageBuilder
{
    /**
     * @return array{message: string, lang: int}
     */
    public function registrationOtp(string $otp, string $locale = 'ar'): array
    {
        return [
            'message' => "رمز التحقق الخاص بك: {$otp}",
            'lang' => 0,
        ];
    }
}
