<?php

declare(strict_types=1);

namespace Modules\User\Services\SmsOtp;

use Modules\User\Enums\OtpPurpose;

final class NullSmsOtpProvider implements SmsOtpProvider
{
    public function sendOtp(string $phone, string $code, OtpPurpose $purpose): void
    {
        // Intentionally no-op; swap this binding with a real Syrian SMS provider later.
    }
}
