<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\RestaurantAssistantQuery;

/**
 * @mixin RestaurantAssistantQuery
 */
final class RestaurantAssistantQueryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'restaurantId' => $this->restaurant_id,
            'inputMode' => $this->input_mode?->value ?? $this->input_mode,
            'queryText' => $this->query_text,
            'matchedRecipeId' => $this->matched_recipe_id,
            'context' => $this->context,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'matchedRecipe' => $this->whenLoaded('matchedRecipe', fn () => [
                'id' => $this->matchedRecipe->id,
                'name' => $this->matchedRecipe->name ?? null,
            ]),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
