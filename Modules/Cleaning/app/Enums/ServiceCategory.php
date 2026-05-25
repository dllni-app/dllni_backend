<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum ServiceCategory: string
{
    case Cleaning = 'cleaning';
    case EventAssistance = 'event_assisent';
}
