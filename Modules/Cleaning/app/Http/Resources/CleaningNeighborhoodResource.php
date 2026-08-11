<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningNeighborhood;

/**
 * @mixin CleaningNeighborhood
 */
final class CleaningNeighborhoodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cityName' => $this->city_name,
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en,
            'displayName' => $this->name_ar ?: $this->name_en,
            'aliases' => $this->aliases ?? [],
            'centerLatitude' => $this->center_latitude !== null ? (float) $this->center_latitude : null,
            'centerLongitude' => $this->center_longitude !== null ? (float) $this->center_longitude : null,
            'sortOrder' => (int) $this->sort_order,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
