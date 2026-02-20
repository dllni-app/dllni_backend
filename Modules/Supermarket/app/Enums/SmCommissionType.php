<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmCommissionType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
