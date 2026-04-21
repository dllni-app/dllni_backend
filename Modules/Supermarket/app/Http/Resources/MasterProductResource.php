<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\MediaResource;
use App\Models\MasterProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MasterProduct
 */
final class MasterProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'masterProductId' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit?->value ?? (string) $this->unit,
            'brand' => $this->brand,
            'description' => $this->description,
            'isActive' => (bool) $this->is_active,
            'primaryImage' => MediaResource::make($this->getFirstMedia())?->toArray($request),

        ];
    }
}
