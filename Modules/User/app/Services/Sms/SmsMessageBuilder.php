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
        if ($locale === 'en') {
            return [
                'message' => "Your verification code is: {$otp}",
                'lang' => 1,
            ];
        }

        return [
            'message' => "\u{0631}\u{0645}\u{0632} \u{0627}\u{0644}\u{062A}\u{062D}\u{0642}\u{0642} \u{0627}\u{0644}\u{062E}\u{0627}\u{0635} \u{0628}\u{0643}: {$otp}",
            'lang' => 0,
        ];
    }
}
