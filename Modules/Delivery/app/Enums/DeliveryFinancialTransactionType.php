<?php

declare(strict_types=1);

namespace Modules\Delivery\Enums;

enum DeliveryFinancialTransactionType: string
{
    case OrderFeeDebit = 'order_fee_debit';
    case CollectionCredit = 'collection_credit';
    case ManualAdjustmentDebit = 'manual_adjustment_debit';
    case ManualAdjustmentCredit = 'manual_adjustment_credit';
    case DisputePenaltyDebit = 'dispute_penalty_debit';
    case DisputeReversalCredit = 'dispute_reversal_credit';
}
