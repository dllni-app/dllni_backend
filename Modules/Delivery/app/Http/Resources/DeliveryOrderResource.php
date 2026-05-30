<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderNumber' => $this->order_number,
            'companyId' => $this->company_id,
            'driverId' => $this->driver_id,
            'status' => $this->status,
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'pickupAddress' => $this->pickup_address,
            'pickupLatitude' => (float) $this->pickup_latitude,
            'pickupLongitude' => (float) $this->pickup_longitude,
            'dropoffAddress' => $this->dropoff_address,
            'dropoffLatitude' => (float) $this->dropoff_latitude,
            'dropoffLongitude' => (float) $this->dropoff_longitude,
            'distanceKm' => (float) $this->distance_km,
            'deliveryFee' => (float) $this->delivery_fee,
            'currency' => $this->currency,
            'acceptedAt' => $this->accepted_at?->toIso8601String(),
            'startedAt' => $this->started_at?->toIso8601String(),
            'pickedUpAt' => $this->picked_up_at?->toIso8601String(),
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            'events' => DeliveryOrderEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
