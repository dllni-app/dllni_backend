<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('cleaning_admin.enums.dispute_status.'.$this->value);
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Resolved, self::Closed, self::Rejected => true,
            default => false,
        };
    }
}
