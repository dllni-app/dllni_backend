<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\RestaurantRole;

/**
 * @mixin RestaurantRole
 */
final class RestaurantRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'staff' => $this->whenLoaded('staff'),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->values()->all()),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
