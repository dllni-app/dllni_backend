<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum EventType: string
{
    case FamilyDinner = 'family_dinner';
    case Birthday = 'birthday';
    case LargeGathering = 'large_gathering';
    case Funeral = 'funeral';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FamilyDinner => 'عشاء عائلي',
            self::Birthday => 'عيد ميلاد',
            self::LargeGathering => 'تجمع كبير',
            self::Funeral => 'جنازة',
            self::Other => 'أخرى',
        };
    }
}
