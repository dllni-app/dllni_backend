<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminExceptionMessage
{
    public static function forUser(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return $exception->getMessage();
        }

        $message = mb_trim($exception->getMessage());

        if ($message !== '' && preg_match('/\p{Arabic}/u', $message) === 1) {
            return $message;
        }

        report($exception);

        return __('admin_navigation.errors.operation_failed');
    }
}
