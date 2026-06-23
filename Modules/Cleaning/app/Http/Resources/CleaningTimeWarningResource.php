<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningTimeWarning;

/**
 * @mixin CleaningTimeWarning
 */
final class CleaningTimeWarningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bookingStatus = null;

        if ($this->relationLoaded('booking') && $this->booking !== null) {
            $bookingStatus = $this->booking->status?->value ?? $this->booking->status;
        }

        $responseStatus = $this->responseStatus((string) $bookingStatus);

        return [
            'id' => $this->id,
            'bookingId' => $this->booking_id,
            'bookingType' => $this->booking_type,
            'bookingStatus' => $bookingStatus,
            'booking_status' => $bookingStatus,
            'status' => $responseStatus,
            'responseStatus' => $responseStatus,
            'response_status' => $responseStatus,
            'customerResponse' => $this->customer_response?->value ?? $this->customer_response,
            'workerResponse' => $this->worker_response?->value ?? $this->worker_response,
            'sentAt' => $this->sent_at?->toDateTimeString(),
            'customerRespondedAt' => $this->customer_responded_at?->toDateTimeString(),
            'workerRespondedAt' => $this->worker_responded_at?->toDateTimeString(),
            'additionalMinutes' => $this->additional_minutes,
            'requestedMinutes' => $this->additional_minutes,
            'additionalAmount' => $this->quoted_amount !== null ? (float) $this->quoted_amount : null,
            'currency' => $this->quoted_currency,
            'priceAppliedAt' => $this->price_applied_at?->toDateTimeString(),
            'workerRejectMessage' => $this->worker_reject_message,
            'booking' => $this->whenLoaded('booking'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }

    private function responseStatus(string $bookingStatus): string
    {
        $workerResponse = $this->worker_response?->value ?? $this->worker_response;

        if ($this->worker_responded_at !== null || $workerResponse !== null) {
            return 'resolved';
        }

        if (in_array($bookingStatus, [
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::Cancelled->value,
        ], true)) {
            return 'closed';
        }

        $customerResponse = $this->customer_response?->value ?? $this->customer_response;

        if ($customerResponse === CleaningTimeWarningResponse::ExtendTime->value) {
            return 'awaiting_worker_response';
        }

        return 'pending';
    }
}
