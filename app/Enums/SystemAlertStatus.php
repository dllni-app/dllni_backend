<?php

declare(strict_types=1);

namespace App\Enums;

enum SystemAlertStatus: string
{
    case New = 'new';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديد',
            self::Acknowledged => 'تمت المشاهدة',
            self::Resolved => 'تم الحل',
        };
    }
}
