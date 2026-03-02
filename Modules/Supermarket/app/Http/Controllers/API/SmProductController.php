<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Http\Requests\SmProductRequest;
use Modules\Supermarket\Http\Requests\SmProductRequests\SmProductFilterRequest;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Services\SmProductService;

final class SmProductController
{
    public function __construct(
        private SmProductService $service
    ) {}

    public function index(SmProductFilterRequest $request): AnonymousResourceCollection
    {
        $products = SmProduct::getQuery()->paginate($request->get('perPage', 20));

        return SmProductResource::collection($products);
    }

    public function availableCount(): JsonResponse
    {
        $availableProductsCount = SmProduct::query()
            ->where('is_available', true)
            ->count();

        return response()->json([
            'count' => $availableProductsCount,
        ]);
    }

    public function store(SmProductRequest $request): SmProductResource
    {
        $product = $this->service->store(SmProductData::from($request->validated()));

        return SmProductResource::make($product->load('store', 'category'));
    }

    public function show(SmProduct $smProduct): SmProductResource
    {
        return SmProductResource::make($smProduct->load('store', 'category'));
    }

    public function update(SmProductRequest $request, SmProduct $smProduct): SmProductResource
    {
        $product = $this->service->update(SmProductData::from($request->validated()), $smProduct);

        return SmProductResource::make($product->load('store', 'category'));
    }

    public function destroy(SmProduct $smProduct): Response
    {
        $smProduct->delete();

        return response()->noContent();
    }
}
