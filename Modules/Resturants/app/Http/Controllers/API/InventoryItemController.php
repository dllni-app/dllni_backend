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
use Modules\Resturants\Support\RestaurantOwnerContext;
use Throwable;

final class InventoryItemController
{
    public function __construct(
        private InventoryItemService $inventoryItemService,
        private RestaurantOwnerContext $ownerContext
    ) {}

    public function index(InventoryItemFilterRequest $request): AnonymousResourceCollection
    {
        $restaurant = $this->ownerContext->restaurant();

        $items = InventoryItem::getQuery()
            ->where('restaurant_id', $restaurant->id)
            ->with(['restaurant', 'products'])
            ->paginate($request->get('perPage', 20));

        return InventoryItemResource::collection($items);
    }

    /** @throws Throwable */
    public function store(InventoryItemRequest $request): InventoryItemResource
    {
        $restaurant = $this->ownerContext->restaurant();

        $item = $this->inventoryItemService->store(
            InventoryItemData::from(array_merge(
                $request->validated(),
                ['restaurantId' => $restaurant->id],
            ))
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
        $restaurant = $this->ownerContext->restaurant();

        $updated = $this->inventoryItemService->update(
            InventoryItemData::from(array_merge(
                $request->validated(),
                ['restaurantId' => $restaurant->id],
            )),
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
