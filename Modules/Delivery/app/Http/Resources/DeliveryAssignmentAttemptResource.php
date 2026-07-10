<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryAssignmentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'driverId' => $this->driver_id,
            'attemptNo' => $this->attempt_no,
            'dispatchWave' => $this->dispatch_wave,
            'candidateTier' => $this->candidate_tier,
            'status' => $this->status,
            'distanceToPickupKm' => $this->distance_to_pickup_km,
            'offeredAt' => $this->offered_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'respondedAt' => $this->responded_at?->toIso8601String(),
            'order' => new DeliveryOrderResource($this->whenLoaded('order')),
        ];
    }
}
