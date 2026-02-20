<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TravelCostConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TravelCostConfig
 */
final class TravelCostConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'maxKm' => (float) $this->max_km,
            'costPerKm' => $this->cost_per_km !== null ? (float) $this->cost_per_km : null,
            'fixedFee' => $this->fixed_fee !== null ? (float) $this->fixed_fee : null,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
