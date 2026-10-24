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
        // Some deployed databases still lack the optional display columns.
        // Reading an absent Eloquent attribute throws MissingAttributeException
        // when strict attribute access is enabled.
        $attributes = $type->getAttributes();

        return [
            'id' => $type->id,
            'section' => $type->section,
            'code' => $this->applicationFlowCode($type),
            'contentCode' => $type->code,
            'value' => $type->booking_value,
            'title' => $type->title,
            'description' => $attributes['description'] ?? null,
            'iconKey' => ($attributes['icon_key'] ?? null) ?: $type->booking_value,
            'imageUrl' => $type->imageUrl(),
            'sortOrder' => $type->sort_order,
        ];
    }

    private function applicationFlowCode(CleaningHomeType $type): string
    {
        if ($type->section !== CleaningHomeType::SECTION_OCCASION) {
            return $type->code;
        }

        return match ($type->booking_value) {
            'birthday' => 'birthday_party',
            'funeral' => 'condolences',
            'family_dinner', 'large_gathering' => $type->booking_value,
            default => 'family_dinner',
        };
    }
}
