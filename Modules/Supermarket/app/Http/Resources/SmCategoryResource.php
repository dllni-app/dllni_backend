<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmCategory;

/**
 * @mixin SmCategory
 */
final class SmCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'sortOrder' => $this->sort_order,
            'imagePath' => $this->image_path,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
