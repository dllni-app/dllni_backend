<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use App\Services\ActivityLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Http\Requests\ProductRequest;
use Modules\Resturants\Http\Requests\ProductRequests\ProductFilterRequest;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Services\ProductService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Throwable;

final class ProductController
{
    public function __construct(
        private ProductService $productService,
        private RestaurantOwnerContext $ownerContext,
        private ActivityLogService $activityLogService
    ) {}

    public function index(ProductFilterRequest $request): AnonymousResourceCollection
    {
        $restaurantId = $request->filled('filter.restaurantId')
            ? (int) $request->input('filter.restaurantId')
            : $this->ownerContext->restaurantId();

        $products = Product::getQuery()
            ->where('restaurant_id', $restaurantId)
            ->with(['restaurant', 'category', 'media'])
            ->paginate($request->get('perPage', 20));

        return ProductResource::collection($products);
    }

    /** @throws Throwable */
    public function store(ProductRequest $request): ProductResource
    {
        $restaurantId = $this->ownerContext->restaurantId();

        $product = $this->productService->store(
            ProductData::from([
                ...$request->validated(),
                'restaurantId' => $restaurantId,
                'primaryImage' => $request->file('primaryImage'),
                'images' => $request->file('images'),
            ])
        );

        return ProductResource::make(
            $product->load(['restaurant', 'category', 'modifierGroups', 'substitutions'])
        );
    }

    public function show(Product $product): ProductResource
    {
        $this->ownerContext->ensureOwnedProduct($product);
        $product->load(['restaurant', 'category', 'modifierGroups', 'substitutions']);

        return ProductResource::make($product);
    }

    /** @throws Throwable */
    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $restaurantId = $this->ownerContext->restaurantId();
        $this->ownerContext->ensureOwnedProduct($product);

        $updated = $this->productService->update(
            ProductData::from([
                ...$request->validated(),
                'restaurantId' => $restaurantId,
                'primaryImage' => $request->file('primaryImage'),
                'images' => $request->file('images'),
            ]),
            $product
        );

        return ProductResource::make(
            $updated->load(['restaurant', 'category', 'modifierGroups', 'substitutions'])
        );
    }

    public function destroy(Product $product): Response
    {
        $this->ownerContext->ensureOwnedProduct($product);
        $productName = $product->name;
        $restaurantId = (int) $product->restaurant_id;
        $product->delete();
        $this->activityLogService->logProductDeleted($productName, $restaurantId);

        return response()->noContent();
    }
}
