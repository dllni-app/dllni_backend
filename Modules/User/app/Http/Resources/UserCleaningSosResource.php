<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use App\Models\SosAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SosAlert
 */
final class UserCleaningSosResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'emergency_type' => $this->emergency_type?->value ?? $this->emergency_type,
            'message' => $this->message,
            'source' => $this->source,
            'status' => $this->status?->value ?? $this->status,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'triggered_at' => $this->triggered_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
