<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportCaseResolution: string
{
    case EmergencyResolved = 'emergency_resolved';
    case WorkerPenalty = 'worker_penalty';
    case Refund = 'refund';
    case Dismissed = 'dismissed';
    case NoAction = 'no_action';

    public function label(): string
    {
        return match ($this) {
            self::EmergencyResolved => 'تمت معالجة الطوارئ',
            self::WorkerPenalty => 'خصم من العامل',
            self::Refund => 'تعويض العميل',
            self::Dismissed => 'رفض الشكوى',
            self::NoAction => 'إغلاق دون إجراء مالي',
        };
    }
}
