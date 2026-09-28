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
use Modules\Supermarket\Services\StoreOwnerContextService;

final class SmCategoryController
{
    public function __construct(
        private SmCategoryService $service,
        private StoreOwnerContextService $context,
    ) {}

    public function index(SmCategoryFilterRequest $request): AnonymousResourceCollection
    {
        $categories = SmCategory::getQuery()
            ->where('store_id', $this->context->ownedStore()->id)
            ->withCount('products')
            ->get();

        return SmCategoryResource::collection($categories);
    }

    public function store(SmCategoryRequest $request): SmCategoryResource
    {
        $category = $this->service->store(SmCategoryData::from($request->validated()));

        return SmCategoryResource::make($category->load('store'));
    }

    public function show(SmCategory $smCategory): SmCategoryResource
    {
        $this->context->store((int) $smCategory->store_id);

        return SmCategoryResource::make($smCategory->load('store'));
    }

    public function update(SmCategoryRequest $request, SmCategory $smCategory): SmCategoryResource
    {
        $this->context->store((int) $smCategory->store_id);

        $category = $this->service->update(SmCategoryData::from($request->validated()), $smCategory);

        return SmCategoryResource::make($category->load('store'));
    }

    public function destroy(SmCategory $smCategory): Response
    {
        $this->context->store((int) $smCategory->store_id);
        $smCategory->delete();

        return response()->noContent();
    }
}
