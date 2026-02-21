<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmAssistantQuery;

/**
 * @mixin SmAssistantQuery
 */
final class SmAssistantQueryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'user' => UserResource::make($this->whenLoaded('user')),
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'inputMode' => $this->input_mode?->value ?? $this->input_mode,
            'queryText' => $this->query_text,
            'voiceFilePath' => $this->voice_file_path,
            'matchedProductIds' => $this->matched_product_ids,
            'matchedRecipeId' => $this->matched_recipe_id,
            'matchedRecipe' => $this->whenLoaded('matchedRecipe', fn () => [
                'id' => $this->matchedRecipe->id,
                'name' => $this->matchedRecipe->name,
            ]),
            'responsePayload' => $this->response_payload,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
