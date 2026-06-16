<?php

declare(strict_types=1);

namespace Modules\Delivery\Enums;

enum DeliverySuspensionReason: string
{
    case Financial = 'financial';
    case Manual = 'manual';
    case Compliance = 'compliance';
}
