<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryFinancialSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'currentBalance' => (float) $this->current_balance,
            'financialLimit' => (float) $this->financial_limit,
            'isSuspended' => $this->is_suspended,
            'suspensionReason' => $this->suspension_reason,
            'currency' => $this->currency,
        ];
    }
}
