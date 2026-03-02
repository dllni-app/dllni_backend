<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return __('cleaning_admin.enums.dispute_status.'.$this->value);
    }
}
