<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportCaseReporterRole: string
{
    case Customer = 'customer';
    case Worker = 'worker';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'العميل',
            self::Worker => 'العامل',
            self::Admin => 'الإدارة',
        };
    }
}
