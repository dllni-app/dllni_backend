<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Services\DeepLinks\CanonicalDeepLinkGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Http\Requests\RestaurantV2CatalogRequest;
use Modules\User\Services\RestaurantV2CatalogService;

final class RestaurantV2CatalogController
{
    public function __construct(
        private readonly RestaurantV2CatalogService $catalog,
        private readonly CanonicalDeepLinkGenerator $deepLinks,
    ) {}

    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        return response()->json($this->catalog->suggestions(
            isset($validated['q']) ? mb_trim($validated['q']) : null,
            (int) ($validated['limit'] ?? 6),
        ));
    }

    public function cuisines(): JsonResponse
    {
        return response()->json($this->catalog->cuisines());
    }

    public function categories(): JsonResponse
    {
        return response()->json($this->catalog->categories());
    }

    public function offers(RestaurantV2CatalogRequest $request): JsonResponse
    {
        return response()->json($this->catalog->offers($request->pageSize(), $request->pageNumber()));
    }

    public function restaurant(RestaurantV2CatalogRequest $request, int $restaurant): JsonResponse
    {
        $data = $this->catalog->restaurantDetail(
            $request,
            $restaurant,
            (int) $request->integer('reviewsPage', 1),
            (int) $request->integer('reviewsPerPage', 10),
            $this->deepLinks->restaurant((string) $restaurant),
        );

        return response()->json(['data' => $data]);
    }

    public function menu(RestaurantV2CatalogRequest $request, int $restaurant): JsonResponse
    {
        $result = $this->catalog->menu(
            $request,
            $restaurant,
            (int) $request->integer('perCategory', 30),
            $request->validated('categoryId') !== null ? (int) $request->validated('categoryId') : null,
            $request->pageNumber(),
        );
        $meta = $result['meta'] ?? null;
        unset($result['meta']);

        return response()->json(['data' => $result, ...($meta !== null ? ['meta' => $meta] : [])]);
    }

    public function product(Request $request, int $product): JsonResponse
    {
        return response()->json(['data' => $this->catalog->productDetail($request, $product, $this->deepLinks->product($product))]);
    }
}
