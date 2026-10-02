<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Data\InventoryItemData;
use Modules\Resturants\Http\Requests\InventoryItemRequest;
use Modules\Resturants\Http\Requests\InventoryItemRequests\InventoryItemFilterRequest;
use Modules\Resturants\Http\Resources\InventoryItemResource;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Product;
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
        $validated = $request->validated();
        $this->assertProductsBelongToRestaurant($validated, (int) $restaurant->id);

        $item = $this->inventoryItemService->store(
            InventoryItemData::from(array_merge(
                $validated,
                ['restaurantId' => $restaurant->id],
            ))
        );

        return InventoryItemResource::make(
            $item->load(['restaurant', 'products'])
        );
    }

    public function show(InventoryItem $inventoryItem): InventoryItemResource
    {
        abort_unless($this->ownerContext->modelBelongsToRestaurant($inventoryItem, $this->ownerContext->restaurantId()), Response::HTTP_NOT_FOUND);
        $inventoryItem->load(['restaurant', 'products']);

        return InventoryItemResource::make($inventoryItem);
    }

    /** @throws Throwable */
    public function update(InventoryItemRequest $request, InventoryItem $inventoryItem): InventoryItemResource
    {
        $restaurant = $this->ownerContext->restaurant();
        abort_unless($this->ownerContext->modelBelongsToRestaurant($inventoryItem, (int) $restaurant->id), Response::HTTP_NOT_FOUND);
        $validated = $request->validated();
        $this->assertProductsBelongToRestaurant($validated, (int) $restaurant->id);

        $updated = $this->inventoryItemService->update(
            InventoryItemData::from(array_merge(
                $validated,
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
        abort_unless($this->ownerContext->modelBelongsToRestaurant($inventoryItem, $this->ownerContext->restaurantId()), Response::HTTP_NOT_FOUND);
        $inventoryItem->delete();

        return response()->noContent();
    }

    /** @param array<string, mixed> $validated */
    private function assertProductsBelongToRestaurant(array $validated, int $restaurantId): void
    {
        $productIds = array_map('intval', $validated['productIds'] ?? []);

        foreach ($validated['products'] ?? [] as $product) {
            if (isset($product['productId'])) {
                $productIds[] = (int) $product['productId'];
            }
        }

        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            return;
        }

        $ownedCount = Product::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('id', $productIds)
            ->count();

        if ($ownedCount !== count($productIds)) {
            throw ValidationException::withMessages([
                'products' => ['All inventory products must belong to the authenticated restaurant.'],
            ]);
        }
    }
}
