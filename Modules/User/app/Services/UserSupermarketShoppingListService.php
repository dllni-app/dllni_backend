<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\MasterProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmSmartList;
use Modules\Supermarket\Models\SmSmartListItem;

final class UserSupermarketShoppingListService
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function index(int $userId): array
    {
        return SmSmartList::query()
            ->where('user_id', $userId)
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (SmSmartList $list): array => $this->listSummary($list))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $userId, int $listId): array
    {
        $list = $this->findOwnedList($userId, $listId);

        return $this->listDetail($list->load([
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function store(int $userId, string $name, ?string $description, bool $isActive): array
    {
        $list = SmSmartList::create([
            'user_id' => $userId,
            'name' => $name,
            'description' => $description,
            'is_active' => $isActive,
        ]);

        return $this->listDetail($list->loadCount('items'));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function updateList(int $userId, int $listId, array $validated): array
    {
        $list = $this->findOwnedList($userId, $listId);
        $data = [];
        if (array_key_exists('name', $validated)) {
            $data['name'] = $validated['name'];
        }
        if (array_key_exists('description', $validated)) {
            $data['description'] = $validated['description'];
        }
        if (array_key_exists('isActive', $validated)) {
            $data['is_active'] = (bool) $validated['isActive'];
        }
        if ($data !== []) {
            $list->update($data);
        }

        return $this->listDetail($list->fresh()->load([
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    public function destroy(int $userId, int $listId): void
    {
        $list = $this->findOwnedList($userId, $listId);
        $list->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function storeItem(
        int $userId,
        int $listId,
        int $masterProductId,
        float $quantity,
        ?string $unit,
        int $sortOrder,
        bool $isIncluded,
    ): array {
        $list = $this->findOwnedList($userId, $listId);
        MasterProduct::query()->where('id', $masterProductId)->firstOrFail();

        SmSmartListItem::create([
            'smart_list_id' => $list->id,
            'master_product_id' => $masterProductId,
            'quantity' => $quantity,
            'unit' => $unit,
            'sort_order' => $sortOrder,
            'is_included' => $isIncluded,
        ]);

        return $this->listDetail($list->fresh()->load([
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function updateItem(
        int $userId,
        int $listId,
        int $itemId,
        ?float $quantity,
        ?int $sortOrder,
        ?bool $isIncluded,
    ): array {
        $list = $this->findOwnedList($userId, $listId);
        $item = SmSmartListItem::query()
            ->where('id', $itemId)
            ->where('smart_list_id', $list->id)
            ->firstOrFail();

        $data = [];
        if ($quantity !== null) {
            $data['quantity'] = $quantity;
        }
        if ($sortOrder !== null) {
            $data['sort_order'] = $sortOrder;
        }
        if ($isIncluded !== null) {
            $data['is_included'] = $isIncluded;
        }

        if ($data !== []) {
            $item->update($data);
        }

        return $this->listDetail($list->fresh()->load([
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    public function destroyItem(int $userId, int $listId, int $itemId): void
    {
        $list = $this->findOwnedList($userId, $listId);
        SmSmartListItem::query()
            ->where('id', $itemId)
            ->where('smart_list_id', $list->id)
            ->firstOrFail()
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function addListToCart(int $userId, int $listId, int $storeId): array
    {
        return DB::transaction(function () use ($userId, $listId, $storeId): array {
            $list = $this->findOwnedList($userId, $listId);
            $list->load([
                'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ]);

            $lines = [];
            foreach ($list->items as $row) {
                if (! $row->is_included) {
                    continue;
                }

                $product = SmProduct::query()
                    ->where('store_id', $storeId)
                    ->where('master_product_id', $row->master_product_id)
                    ->where('is_available', true)
                    ->orderBy('id')
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'storeId' => [
                            "No available product found in this store for master product id {$row->master_product_id}.",
                        ],
                    ]);
                }

                $lines[] = [
                    'productId' => $product->id,
                    'quantity' => max(1, (int) round((float) $row->quantity)),
                ];
            }

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'items' => ['There are no included items to add to the cart.'],
                ]);
            }

            return $this->carts->addLinesForStore($userId, $storeId, $lines);
        });
    }

    private function findOwnedList(int $userId, int $listId): SmSmartList
    {
        return SmSmartList::query()
            ->where('id', $listId)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function listSummary(SmSmartList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'isActive' => (bool) $list->is_active,
            'itemsCount' => (int) ($list->items_count ?? $list->items()->count()),
            'createdAt' => $list->created_at?->toDateTimeString(),
            'updatedAt' => $list->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listDetail(SmSmartList $list): array
    {
        $items = $list->relationLoaded('items')
            ? $list->items
            : $list->items()->orderBy('sort_order')->orderBy('id')->with('masterProduct')->get();

        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'isActive' => (bool) $list->is_active,
            'items' => $items->map(fn (SmSmartListItem $item): array => $this->itemPayload($item))->values()->all(),
            'createdAt' => $list->created_at?->toDateTimeString(),
            'updatedAt' => $list->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(SmSmartListItem $item): array
    {
        return [
            'id' => $item->id,
            'masterProductId' => $item->master_product_id,
            'name' => $item->masterProduct?->name,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'sortOrder' => (int) $item->sort_order,
            'isIncluded' => (bool) ($item->is_included ?? true),
            'createdAt' => $item->created_at?->toDateTimeString(),
            'updatedAt' => $item->updated_at?->toDateTimeString(),
        ];
    }
}
