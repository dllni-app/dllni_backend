<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\CategoryData;
use Modules\Resturants\Http\Requests\CategoryRequest;
use Modules\Resturants\Http\Requests\CategoryRequests\CategoryFilterRequest;
use Modules\Resturants\Http\Resources\CategoryResource;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Services\CategoryService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Throwable;

final class CategoryController
{
    public function __construct(
        private CategoryService $categoryService,
        private RestaurantOwnerContext $ownerContext,
    ) {}

    public function index(CategoryFilterRequest $request): AnonymousResourceCollection
    {
        $categories = Category::getQuery()
            ->where('restaurant_id', $this->ownerContext->restaurantId())
            ->with(['restaurant', 'products'])
            ->paginate($request->get('perPage', 10));

        return CategoryResource::collection($categories);
    }

    /** @throws Throwable */
    public function store(CategoryRequest $request): CategoryResource
    {
        $category = $this->categoryService->store(
            CategoryData::from([
                ...$request->validated(),
                'restaurantId' => $this->ownerContext->restaurantId(),
            ])
        );

        return CategoryResource::make($category->load(['restaurant', 'products']));
    }

    public function show(Category $category): CategoryResource
    {
        abort_unless($this->ownerContext->modelBelongsToRestaurant($category, $this->ownerContext->restaurantId()), Response::HTTP_NOT_FOUND);
        $category->load(['restaurant', 'products']);

        return CategoryResource::make($category);
    }

    /** @throws Throwable */
    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        abort_unless($this->ownerContext->modelBelongsToRestaurant($category, $this->ownerContext->restaurantId()), Response::HTTP_NOT_FOUND);

        $updated = $this->categoryService->update(
            CategoryData::from([
                ...$request->validated(),
                'restaurantId' => $this->ownerContext->restaurantId(),
            ]),
            $category
        );

        return CategoryResource::make($updated->load(['restaurant', 'products']));
    }

    public function destroy(Category $category): Response
    {
        abort_unless($this->ownerContext->modelBelongsToRestaurant($category, $this->ownerContext->restaurantId()), Response::HTTP_NOT_FOUND);
        $category->delete();

        return response()->noContent();
    }
}
