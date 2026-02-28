<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum EventBookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case TeamAssigned = 'team_assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Confirmed => 'مؤكد',
            self::TeamAssigned => 'تم تعيين الفريق',
            self::InProgress => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغي',
        };
    }
}
