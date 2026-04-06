<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\CuisineType;

/**
 * @mixin CuisineType
 */
final class UserRestaurantCuisineCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        if ($this->relationLoaded('restaurants')) {
            $restaurant = $this->restaurants->first();
            if ($restaurant !== null) {
                $imageUrl = $restaurant->getFirstMediaUrl('primary-image') ?: null;
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image' => $imageUrl,
        ];
    }
}
