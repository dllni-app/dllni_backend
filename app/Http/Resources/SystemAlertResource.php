<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SystemAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SystemAlert
 */
final class SystemAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bookingId' => $this->booking_id,
            'bookingType' => $this->booking_type,
            'alertType' => $this->alert_type?->value ?? $this->alert_type,
            'severity' => $this->severity?->value ?? $this->severity,
            'status' => $this->status?->value ?? $this->status,
            'payload' => $this->payload,
            'booking' => $this->whenLoaded('booking'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
