<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Delivery\Models\DeliveryDriver;

final class DeliveryDriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DeliveryDriver $driver */
        $driver = $this->resource;

        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'companyId' => $this->company_id,
            'firstName' => $this->first_name,
            'displayName' => $driver->relationLoaded('user') && $driver->user ? $driver->user->name : $this->first_name,
            'phone' => $this->phone,
            'vehicleType' => $this->vehicle_type,
            'plateNumber' => $this->plate_number,
            'availabilityStatus' => $this->availability_status,
            'isActive' => $this->is_active,
            'isSuspended' => $this->is_suspended,
            'trustScore' => $this->trust_score,
            'openDisputesCount' => $this->open_disputes_count,
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'latestLocation' => $driver->relationLoaded('latestLocation') && $driver->latestLocation ? [
                'id' => $driver->latestLocation->id,
                'driverId' => $driver->latestLocation->driver_id,
                'latitude' => (float) $driver->latestLocation->latitude,
                'longitude' => (float) $driver->latestLocation->longitude,
                'accuracy' => $driver->latestLocation->accuracy ? (float) $driver->latestLocation->accuracy : null,
                'speed' => $driver->latestLocation->speed ? (float) $driver->latestLocation->speed : null,
                'heading' => $driver->latestLocation->heading ? (float) $driver->latestLocation->heading : null,
                'recordedAt' => $driver->latestLocation->recorded_at?->toIso8601String(),
            ] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
