<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryDriverLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'driverId' => $this->driver_id,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'accuracy' => $this->accuracy ? (float) $this->accuracy : null,
            'speed' => $this->speed ? (float) $this->speed : null,
            'heading' => $this->heading ? (float) $this->heading : null,
            'recordedAt' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
