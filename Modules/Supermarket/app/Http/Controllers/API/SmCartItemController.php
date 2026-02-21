<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmCartItemData;
use Modules\Supermarket\Http\Requests\SmCartItemRequest;
use Modules\Supermarket\Http\Requests\SmCartItemRequests\SmCartItemFilterRequest;
use Modules\Supermarket\Http\Resources\SmCartItemResource;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Services\SmCartItemService;

final class SmCartItemController
{
    public function __construct(
        private SmCartItemService $service
    ) {}

    public function index(SmCartItemFilterRequest $request): AnonymousResourceCollection
    {
        $items = SmCartItem::getQuery()->paginate($request->get('perPage', 20));

        return SmCartItemResource::collection($items);
    }

    public function store(SmCartItemRequest $request): SmCartItemResource
    {
        $item = $this->service->store(SmCartItemData::from($request->validated()));

        return SmCartItemResource::make($item->load(['cart', 'product']));
    }

    public function show(SmCartItem $smCartItem): SmCartItemResource
    {
        return SmCartItemResource::make($smCartItem->load(['cart', 'product']));
    }

    public function update(SmCartItemRequest $request, SmCartItem $smCartItem): SmCartItemResource
    {
        $item = $this->service->update(SmCartItemData::from($request->validated()), $smCartItem);

        return SmCartItemResource::make($item->load(['cart', 'product']));
    }

    public function destroy(SmCartItem $smCartItem): Response
    {
        $smCartItem->delete();

        return response()->noContent();
    }
}
