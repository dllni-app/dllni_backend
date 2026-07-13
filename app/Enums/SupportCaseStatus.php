<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportCaseStatus: string
{
    case New = 'new';
    case Acknowledged = 'acknowledged';
    case UnderReview = 'under_review';
    case WaitingParty = 'waiting_party';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديد',
            self::Acknowledged => 'تم الاستلام',
            self::UnderReview => 'قيد المراجعة',
            self::WaitingParty => 'بانتظار أحد الأطراف',
            self::Resolved => 'تم الحل',
            self::Closed => 'مغلق',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Closed], true);
    }

    public static function activeValues(): array
    {
        return [
            self::New->value,
            self::Acknowledged->value,
            self::UnderReview->value,
            self::WaitingParty->value,
        ];
    }
}
