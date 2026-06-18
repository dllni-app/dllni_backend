<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CleaningDepositTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'balanceBefore' => (float) $this->balance_before,
            'balanceAfter' => (float) $this->balance_after,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'cleaningBookingId' => $this->cleaning_booking_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
