<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningService;

/**
 * @mixin CleaningService
 */
final class CleaningServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category?->value ?? $this->category,
            'description' => $this->description,
            'price' => $this->price !== null ? (float) $this->price : 0.0,
            'isActive' => $this->is_active,
            'pricing' => ServicePricingResource::collection($this->whenLoaded('pricing')),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
