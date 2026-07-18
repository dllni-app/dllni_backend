<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningHomeType;

/**
 * @mixin CleaningHomeType
 */
final class UserCleaningHomeTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CleaningHomeType $type */
        $type = $this->resource;

        return [
            'id' => $type->id,
            'section' => $type->section,
            'code' => $type->code,
            'value' => $type->booking_value,
            'title' => $type->title,
            'imageUrl' => $type->imageUrl(),
            'sortOrder' => $type->sort_order,
        ];
    }
}
