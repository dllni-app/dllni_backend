<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Models\CleaningBanner;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\User\Http\Resources\UserCleaningBannerResource;
use Modules\User\Http\Resources\UserCleaningHomeTypeResource;

final class UserCleaningHomeContentController
{
    public function __invoke(): JsonResponse
    {
        $banners = CleaningBanner::query()
            ->visibleNow()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $propertyTypes = CleaningHomeType::query()
            ->active()
            ->forSection(CleaningHomeType::SECTION_PROPERTY)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $occasionTypes = CleaningHomeType::query()
            ->active()
            ->forSection(CleaningHomeType::SECTION_OCCASION)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'banners' => UserCleaningBannerResource::collection($banners),
            'propertyTypes' => UserCleaningHomeTypeResource::collection($propertyTypes),
            'occasionTypes' => UserCleaningHomeTypeResource::collection($occasionTypes),
        ]);
    }
}
