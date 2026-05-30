<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Delivery\Models\DeliveryFinancialTransaction;

/**
 * @mixin DeliveryFinancialTransaction
 */
final class DeliveryFinancialTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transactionType' => $this->transaction_type?->value ?? $this->transaction_type,
            'direction' => $this->direction?->value ?? $this->direction,
            'amount' => (float) $this->amount,
            'balanceBefore' => (float) $this->balance_before,
            'balanceAfter' => (float) $this->balance_after,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'note' => $this->note,
            'metadata' => $this->metadata,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
