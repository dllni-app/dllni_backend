<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Models\CleaningBanner;
use Modules\User\Http\Resources\UserCleaningBannerResource;

final class UserCleaningBannersController
{
    public function __invoke(): JsonResponse
    {
        $banners = CleaningBanner::query()
            ->visibleNow()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'banners' => UserCleaningBannerResource::collection($banners),
        ]);
    }
}
