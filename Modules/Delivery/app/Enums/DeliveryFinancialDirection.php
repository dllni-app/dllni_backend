<?php

declare(strict_types=1);

namespace Modules\Delivery\Enums;

enum DeliveryFinancialDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
