<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingStatus: string
{
    case Pending = 'pending';
    case WorkerAssigned = 'worker_assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::WorkerAssigned => 'تم تعيين عامل',
            self::InProgress => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغي',
        };
    }
}
