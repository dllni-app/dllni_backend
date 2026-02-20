<?php

declare(strict_types=1);

namespace App\Enums;

enum SOSStatus: string
{
    case Triggered = 'triggered';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
