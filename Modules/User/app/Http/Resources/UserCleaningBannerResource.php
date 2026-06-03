<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningBanner;

/**
 * @mixin CleaningBanner
 */
final class UserCleaningBannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CleaningBanner $banner */
        $banner = $this->resource;

        return [
            'id' => $banner->id,
            'title' => $banner->title,
            'subtitle' => $banner->subtitle,
            'imageUrl' => $banner->imageUrl(),
            'targetUrl' => $banner->target_url,
            'sortOrder' => $banner->sort_order,
            'isActive' => $banner->is_active,
            'startsAt' => $banner->starts_at?->toIso8601String(),
            'endsAt' => $banner->ends_at?->toIso8601String(),
        ];
    }
}
