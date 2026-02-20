<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\RestaurantRecurringOrder;

/**
 * @mixin RestaurantRecurringOrder
 */
final class RestaurantRecurringOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'restaurantId' => $this->restaurant_id,
            'status' => $this->status?->value ?? $this->status,
            'frequency' => $this->frequency,
            'nextRunAt' => $this->next_run_at?->toDateTimeString(),
            'lastRunAt' => $this->last_run_at?->toDateTimeString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'items' => $this->whenLoaded('items'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
