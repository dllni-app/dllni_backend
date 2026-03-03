<?php

declare(strict_types=1);

namespace App\Enums;

enum SystemAlertStatus: string
{
    case New = 'new';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return __('cleaning_admin.enums.system_alert_status.' . $this->value);
    }
}
