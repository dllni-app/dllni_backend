<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningTimeWarning;

/**
 * @mixin CleaningTimeWarning
 */
final class CleaningTimeWarningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bookingId' => $this->booking_id,
            'bookingType' => $this->booking_type,
            'customerResponse' => $this->customer_response?->value ?? $this->customer_response,
            'workerResponse' => $this->worker_response?->value ?? $this->worker_response,
            'sentAt' => $this->sent_at?->toDateTimeString(),
            'customerRespondedAt' => $this->customer_responded_at?->toDateTimeString(),
            'workerRespondedAt' => $this->worker_responded_at?->toDateTimeString(),
            'additionalMinutes' => $this->additional_minutes,
            'workerRejectMessage' => $this->worker_reject_message,
            'booking' => $this->whenLoaded('booking'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
