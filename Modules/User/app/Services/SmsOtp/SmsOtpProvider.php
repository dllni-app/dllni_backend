<?php

declare(strict_types=1);

namespace Modules\User\Services\SmsOtp;

use Modules\User\Enums\OtpPurpose;

interface SmsOtpProvider
{
    public function sendOtp(string $phone, string $code, OtpPurpose $purpose): void;
}
