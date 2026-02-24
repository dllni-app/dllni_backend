<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\InventoryItemData;
use Modules\Resturants\Http\Requests\InventoryItemRequest;
use Modules\Resturants\Http\Requests\InventoryItemRequests\InventoryItemFilterRequest;
use Modules\Resturants\Http\Resources\InventoryItemResource;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Services\InventoryItemService;
use Throwable;

final class InventoryItemController
{
    public function __construct(
        private InventoryItemService $inventoryItemService
    ) {}

    public function index(InventoryItemFilterRequest $request): AnonymousResourceCollection
    {
        $items = InventoryItem::getQuery()
            ->with(['restaurant'])
            ->paginate($request->get('perPage', 20));

        return InventoryItemResource::collection($items);
    }

    /** @throws Throwable */
    public function store(InventoryItemRequest $request): InventoryItemResource
    {
        $item = $this->inventoryItemService->store(
            InventoryItemData::from($request->validated())
        );

        return InventoryItemResource::make(
            $item->load(['restaurant', 'products'])
        );
    }

    public function show(InventoryItem $inventoryItem): InventoryItemResource
    {
        $inventoryItem->load(['restaurant', 'products']);

        return InventoryItemResource::make($inventoryItem);
    }

    /** @throws Throwable */
    public function update(InventoryItemRequest $request, InventoryItem $inventoryItem): InventoryItemResource
    {
        $updated = $this->inventoryItemService->update(
            InventoryItemData::from($request->validated()),
            $inventoryItem
        );

        return InventoryItemResource::make(
            $updated->load(['restaurant', 'products'])
        );
    }

    public function destroy(InventoryItem $inventoryItem): Response
    {
        $inventoryItem->delete();

        return response()->noContent();
    }
}
