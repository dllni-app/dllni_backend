<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportCasePriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'حرجة',
            self::High => 'عالية',
            self::Normal => 'عادية',
        };
    }
}
