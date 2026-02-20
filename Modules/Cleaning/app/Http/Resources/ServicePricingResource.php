<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\ServicePricing;

/**
 * @mixin ServicePricing
 */
final class ServicePricingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cleaningServiceId' => $this->cleaning_service_id,
            'propertyType' => $this->property_type,
            'livingRoomSize' => $this->living_room_size,
            'basePrice' => (float) $this->base_price,
            'pricePerSqm' => $this->price_per_sqm !== null ? (float) $this->price_per_sqm : null,
            'minHours' => $this->min_hours !== null ? (float) $this->min_hours : null,
            'cleaningService' => $this->whenLoaded('cleaningService', fn () => [
                'id' => $this->cleaningService->id,
                'name' => $this->cleaningService->name,
                'slug' => $this->cleaningService->slug,
            ]),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
