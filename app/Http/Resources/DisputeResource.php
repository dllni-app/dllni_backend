<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispute
 */
final class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bookingId' => $this->booking_id,
            'bookingType' => $this->booking_type,
            'ticketNumber' => $this->ticket_number,
            'category' => $this->category?->value ?? $this->category,
            'status' => $this->status?->value ?? $this->status,
            'resolution' => $this->resolution?->value ?? $this->resolution,
            'booking' => $this->whenLoaded('booking'),
            'messages' => $this->whenLoaded('messages'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
