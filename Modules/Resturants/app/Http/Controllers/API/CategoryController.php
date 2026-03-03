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
use Throwable;

final class CategoryController
{
    public function __construct(
        private CategoryService $categoryService
    ) {}

    public function index(CategoryFilterRequest $request): AnonymousResourceCollection
    {
        $categories = Category::getQuery()
            ->with(['restaurant', 'products'])
            ->paginate($request->get('perPage', 10));

        return CategoryResource::collection($categories);
    }

    /** @throws Throwable */
    public function store(CategoryRequest $request): CategoryResource
    {
        $category = $this->categoryService->store(
            CategoryData::from($request->validated())
        );

        return CategoryResource::make($category->load(['restaurant', 'products']));
    }

    public function show(Category $category): CategoryResource
    {
        $category->load(['restaurant', 'products']);

        return CategoryResource::make($category);
    }

    /** @throws Throwable */
    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        $updated = $this->categoryService->update(
            CategoryData::from($request->validated()),
            $category
        );

        return CategoryResource::make($updated->load(['restaurant', 'products']));
    }

    public function destroy(Category $category): Response
    {
        $category->delete();

        return response()->noContent();
    }
}
