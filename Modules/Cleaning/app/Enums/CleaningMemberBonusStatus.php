<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningMemberBonusStatus: string
{
    case Pending = 'pending';
    case Activated = 'activated';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار تفعيل الإدارة',
            self::Activated => 'مفعلة',
            self::Cancelled => 'ملغاة',
        };
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [
            self::Pending->value,
            self::Activated->value,
        ];
    }
}
