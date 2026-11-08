<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantV2HomeRequest;
use Modules\User\Services\RestaurantV2CatalogService;

final class RestaurantV2HomeController
{
    public function __construct(private readonly RestaurantV2CatalogService $catalog) {}

    public function __invoke(RestaurantV2HomeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->home($request, (int) $request->integer('perPage', 8))]);
    }

    public function section(RestaurantV2HomeRequest $request, string $section): JsonResponse
    {
        return response()->json(['data' => $this->catalog->homeSection(
            $request,
            $section,
            (int) $request->integer('perPage', 8),
            $request->pageNumber(),
        )]);
    }
}
