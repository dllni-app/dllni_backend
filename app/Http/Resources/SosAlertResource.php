<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SosAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SosAlert
 */
final class SosAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bookingId' => $this->booking_id,
            'bookingType' => $this->booking_type,
            'emergencyType' => $this->emergency_type?->value ?? $this->emergency_type,
            'status' => $this->status?->value ?? $this->status,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'triggeredAt' => $this->triggered_at?->toDateTimeString(),
            'resolvedAt' => $this->resolved_at?->toDateTimeString(),
            'booking' => $this->whenLoaded('booking'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
