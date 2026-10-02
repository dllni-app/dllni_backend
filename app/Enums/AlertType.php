<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertType: string
{
    case DelayedRating = 'delayed_rating';
    case FrozenGPS = 'frozen_gps';
    case StalledProgress = 'stalled_progress';
    case ReadyPickupOverdue = 'ready_pickup_overdue';
    case DeliveryDispatchExhausted = 'delivery_dispatch_exhausted';
    case StaleDriverLocation = 'stale_driver_location';
    case SOSTriggered = 'sos_triggered';
    case CleaningBookingDispute = 'cleaning_booking_dispute';
    case TimeExpired = 'time_expired';
    case OverdueCompletion = 'overdue_completion';
    case AnomalyDetected = 'anomaly_detected';
    case PriceAdjustmentRequested = 'price_adjustment_requested';

    public function label(): string
    {
        return match ($this) {
            self::StalledProgress => __('platform_alerts.types.stalled_progress'),
            self::ReadyPickupOverdue => __('platform_alerts.types.ready_pickup_overdue'),
            self::DeliveryDispatchExhausted => __('platform_alerts.types.delivery_dispatch_exhausted'),
            self::StaleDriverLocation => __('platform_alerts.types.stale_driver_location'),
            self::PriceAdjustmentRequested => 'Price adjustment requested',
            default => __('cleaning_admin.enums.alert_type.'.$this->value),
        };
    }
}
