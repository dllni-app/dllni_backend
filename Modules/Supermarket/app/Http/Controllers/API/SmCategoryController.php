<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmCategoryData;
use Modules\Supermarket\Http\Requests\SmCategoryRequest;
use Modules\Supermarket\Http\Requests\SmCategoryRequests\SmCategoryFilterRequest;
use Modules\Supermarket\Http\Resources\SmCategoryResource;
use Modules\Supermarket\Models\SmCategory;
use Modules\Supermarket\Services\SmCategoryService;

final class SmCategoryController
{
    public function __construct(
        private SmCategoryService $service
    ) {}

    public function index(SmCategoryFilterRequest $request): AnonymousResourceCollection
    {
        $categories = SmCategory::getQuery()
            ->withCount('products')
            ->paginate($request->get('perPage', 20));

        return SmCategoryResource::collection($categories);
    }

    public function store(SmCategoryRequest $request): SmCategoryResource
    {
        $category = $this->service->store(SmCategoryData::from($request->validated()));

        return SmCategoryResource::make($category->load('store'));
    }

    public function show(SmCategory $smCategory): SmCategoryResource
    {
        return SmCategoryResource::make($smCategory->load('store'));
    }

    public function update(SmCategoryRequest $request, SmCategory $smCategory): SmCategoryResource
    {
        $category = $this->service->update(SmCategoryData::from($request->validated()), $smCategory);

        return SmCategoryResource::make($category->load('store'));
    }

    public function destroy(SmCategory $smCategory): Response
    {
        $smCategory->delete();

        return response()->noContent();
    }
}
