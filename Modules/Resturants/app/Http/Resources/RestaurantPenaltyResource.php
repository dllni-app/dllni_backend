<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\RestaurantPenalty;

/**
 * @mixin RestaurantPenalty
 */
final class RestaurantPenaltyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'penaltyType' => $this->penalty_type?->value ?? $this->penalty_type,
            'amount' => $this->amount ? (float) $this->amount : null,
            'reason' => $this->reason,
            'resolvedAt' => $this->resolved_at?->toDateTimeString(),
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
