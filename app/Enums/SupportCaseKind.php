<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportCaseKind: string
{
    case Emergency = 'emergency';
    case Complaint = 'complaint';

    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'بلاغ طوارئ',
            self::Complaint => 'شكوى أو نزاع',
        };
    }
}
