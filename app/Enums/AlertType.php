<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertType: string
{
    case DelayedRating = 'delayed_rating';
    case FrozenGPS = 'frozen_gps';
    case SOSTriggered = 'sos_triggered';
    case TimeExpired = 'time_expired';
    case OverdueCompletion = 'overdue_completion';
    case AnomalyDetected = 'anomaly_detected';

    public function label(): string
    {
        return match ($this) {
            self::DelayedRating => 'تأخر التقييم المتبادل',
            self::FrozenGPS => 'موقع مجمد',
            self::SOSTriggered => 'استغاثة',
            self::TimeExpired => 'تجاوز الوقت',
            self::OverdueCompletion => 'تجاوز الوقت دون انتهاء',
            self::AnomalyDetected => 'شذوذ',
        };
    }
}
