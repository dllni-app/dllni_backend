<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeResolution: string
{
    case FullRefund = 'full_refund';
    case PartialRefund = 'partial_refund';
    case WorkerPenalty = 'worker_penalty';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return __('cleaning_admin.enums.dispute_resolution.'.$this->value);
    }
}
