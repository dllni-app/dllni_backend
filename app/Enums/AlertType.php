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
    case PriceAdjustmentRequested = 'price_adjustment_requested';

    public function label(): string
    {
        return match ($this) {
            self::PriceAdjustmentRequested => 'Price adjustment requested',
            default => __('cleaning_admin.enums.alert_type.'.$this->value),
        };
    }
}
