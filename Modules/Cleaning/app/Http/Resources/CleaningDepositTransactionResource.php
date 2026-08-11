<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use App\Models\CleaningDepositTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CleaningDepositTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CleaningDepositTransaction $transaction */
        $transaction = $this->resource;

        return [
            'id' => $transaction->id,
            'type' => $transaction->publicType(),
            'amount' => $transaction->publicAmount(),
            'debtSettledAmount' => (float) ($transaction->debt_settled_amount ?? 0),
            'adminRevenueWithdrawnAmount' => (float) ($transaction->admin_revenue_withdrawn_amount ?? 0),
            'depositBalanceBefore' => (float) $transaction->balance_before,
            'depositBalanceAfter' => (float) $transaction->balance_after,
            'debtBalanceBefore' => (float) ($transaction->debt_balance_before ?? 0),
            'debtBalanceAfter' => (float) ($transaction->debt_balance_after ?? 0),
            'balanceBefore' => (float) $transaction->balance_before,
            'balanceAfter' => (float) $transaction->balance_after,
            'reference' => $transaction->reference,
            'notes' => $transaction->notes,
            'createdAt' => $transaction->created_at?->toIso8601String(),
            'updatedAt' => $transaction->updated_at?->toIso8601String(),
        ];
    }
}
