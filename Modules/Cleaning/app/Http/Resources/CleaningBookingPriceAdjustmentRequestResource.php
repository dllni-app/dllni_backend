<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;

/** @mixin CleaningBookingPriceAdjustmentRequest */
final class CleaningBookingPriceAdjustmentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof CleaningPriceAdjustmentRequestStatus
            ? $this->status
            : CleaningPriceAdjustmentRequestStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->id,
            'bookingId' => $this->cleaning_booking_id,
            'booking_id' => $this->cleaning_booking_id,
            'workerId' => $this->worker_id,
            'worker_id' => $this->worker_id,
            'oldTotalPrice' => (float) $this->old_total_price,
            'old_total_price' => (float) $this->old_total_price,
            'proposedTotalPrice' => (float) $this->proposed_total_price,
            'proposed_total_price' => (float) $this->proposed_total_price,
            'reason' => $this->reason,
            'status' => $status?->value ?? (string) $this->status,
            'statusLabel' => $status?->label() ?? (string) $this->status,
            'status_label' => $status?->label() ?? (string) $this->status,
            'adminFinalTotalPrice' => $this->admin_final_total_price !== null ? (float) $this->admin_final_total_price : null,
            'admin_final_total_price' => $this->admin_final_total_price !== null ? (float) $this->admin_final_total_price : null,
            'adminNote' => $this->admin_note,
            'admin_note' => $this->admin_note,
            'reviewedBy' => $this->reviewed_by,
            'reviewed_by' => $this->reviewed_by,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
