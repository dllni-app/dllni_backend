<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return __('cleaning_admin.enums.alert_severity.'.$this->value);
    }
}
