<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\HomepageRequest;
use Modules\User\Services\HomepageService;

final class SmHomeNearbyStoresController
{
    public function __construct(
        private HomepageService $homepageService,
    ) {}

    public function __invoke(HomepageRequest $request): JsonResponse
    {
        return response()->json($this->homepageService->supermarketNearbyStores($request));
    }
}
