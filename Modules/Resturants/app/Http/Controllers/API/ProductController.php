<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Http\Requests\ProductRequest;
use Modules\Resturants\Http\Requests\ProductRequests\ProductFilterRequest;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Services\ProductService;
use Throwable;

final class ProductController
{
    public function __construct(
        private ProductService $productService
    ) {}

    public function index(ProductFilterRequest $request): AnonymousResourceCollection
    {
        $products = Product::getQuery()
            ->with(['restaurant', 'category'])
            ->paginate($request->get('perPage', 20));

        return ProductResource::collection($products);
    }

    /** @throws Throwable */
    public function store(ProductRequest $request): ProductResource
    {
        $product = $this->productService->store(
            ProductData::from($request->validated())
        );

        return ProductResource::make(
            $product->load(['restaurant', 'category', 'modifierGroups', 'substitutions'])
        );
    }

    public function show(Product $product): ProductResource
    {
        $product->load(['restaurant', 'category', 'modifierGroups', 'substitutions']);

        return ProductResource::make($product);
    }

    /** @throws Throwable */
    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $updated = $this->productService->update(
            ProductData::from($request->validated()),
            $product
        );

        return ProductResource::make(
            $updated->load(['restaurant', 'category', 'modifierGroups', 'substitutions'])
        );
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }
}
