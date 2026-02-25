<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmInventoryLogType: string
{
    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case OrderDeduction = 'order_deduction';
    case ManualAdjustment = 'manual_adjustment';
    case AuditCorrection = 'audit_correction';
    case Return = 'return';
    case Expired = 'expired';
    case Damaged = 'damaged';
}
