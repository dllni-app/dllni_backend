<?php

declare(strict_types=1);

namespace Modules\User\Enums;

enum OtpPurpose: string
{
    case Register = 'register';
    case Login = 'login';
    case ResetPassword = 'reset_password';
}
