<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\Product;

final class UserRestaurantCartService
{
    public function show(int $userId): array
    {
        $cart = Cart::query()
            ->where('user_id', $userId)
            ->with(['items.product.restaurant.media', 'items.product.media', 'items.modifiers'])
            ->first();

        if (! $cart) {
            return [
                'id' => null,
                'merchant' => null,
                'items' => [],
                'merchantGroups' => [],
                'amounts' => [
                    'subtotal' => 0.0,
                    'total' => 0.0,
                ],
            ];
        }

        return $this->toPayload($cart);
    }

    public function addItem(
        int $userId,
        int $productId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $note = null,
    ): array {
        return DB::transaction(function () use ($userId, $productId, $quantity, $modifierIds, $substituteProductId, $note): array {
            $product = Product::query()
                ->with(['modifierGroups.modifiers'])
                ->findOrFail($productId);

            if (! $product->restaurant_id) {
                throw ValidationException::withMessages([
                    'productId' => ['The selected product is not linked to a restaurant.'],
                ]);
            }

            $cart = $this->resolveActiveCart($userId);

            $modifierIds = $this->normalizeModifierIds($modifierIds);
            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $note = $this->normalizeNote($note);
            $unitPrice = $this->calculateUnitPrice($product, $modifiers);
            $operation = 'created';

            $item = $this->findMatchingItem(
                cartId: (int) $cart->id,
                productId: (int) $product->id,
                modifierIds: $modifierIds,
                substituteProductId: $substituteProductId,
                note: $note,
            );

            if ($item) {
                $operation = 'updated';
                $item->update([
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $quantity,
                ]);
            } else {
                $item = CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'substitute_product_id' => $substituteProductId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $quantity,
                    'special_instructions' => $note,
                ]);
            }

            $item->modifiers()->sync(
                $modifiers->mapWithKeys(fn (Modifier $modifier): array => [
                    $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
                ])->all()
            );

            $freshCart = $cart->fresh(['items.product.restaurant.media', 'items.product.media', 'items.modifiers']);

            return [
                'cartId' => $cart->id,
                'itemId' => $item->id,
                'quantity' => (int) $item->quantity,
                'operation' => $operation,
                'cartProductsCount' => (int) ($freshCart?->items->sum('quantity') ?? 0),
                'cart' => $this->toPayload($freshCart ?? $cart),
            ];
        });
    }

    public function updateItem(
        int $userId,
        int $itemId,
        int $quantity,
        ?array $modifierIds = null,
        ?int $substituteProductId = null,
        ?string $note = null,
        bool $replaceSubstituteProduct = true,
        bool $replaceNote = true,
    ): array {
        return DB::transaction(function () use ($userId, $itemId, $quantity, $modifierIds, $substituteProductId, $note, $replaceSubstituteProduct, $replaceNote): array {
            $item = CartItem::query()
                ->whereKey($itemId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with(['product.modifierGroups.modifiers', 'cart', 'modifiers'])
                ->lockForUpdate()
                ->firstOrFail();

            $product = $item->product;
            $cart = $item->cart;
            $nextModifierIds = $modifierIds === null
                ? $this->normalizeModifierIds($item->modifiers->pluck('id')->all())
                : $this->normalizeModifierIds($modifierIds);
            $nextSubstituteProductId = $replaceSubstituteProduct
                ? $substituteProductId
                : ($item->substitute_product_id !== null ? (int) $item->substitute_product_id : null);
            $nextNote = $replaceNote ? $this->normalizeNote($note) : $this->normalizeNote($item->special_instructions);

            $modifiers = $this->validatedModifiers($product, $nextModifierIds);
            $unitPrice = $this->calculateUnitPrice($product, $modifiers);

            $matchingItem = $this->findMatchingItem(
                cartId: (int) $cart->id,
                productId: (int) $product->id,
                modifierIds: $nextModifierIds,
                substituteProductId: $nextSubstituteProductId,
                note: $nextNote,
                exceptItemId: (int) $item->id,
            );

            $targetItem = $matchingItem ?: $item;
            $targetItem->update([
                'quantity' => $quantity,
                'substitute_product_id' => $nextSubstituteProductId,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $nextNote,
            ]);

            if ($modifierIds !== null || $matchingItem) {
                $targetItem->modifiers()->sync(
                    $modifiers->mapWithKeys(fn (Modifier $modifier): array => [
                        $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
                    ])->all()
                );
            }

            if ($matchingItem) {
                $item->delete();
            }

            return $this->toPayload($cart->fresh(['items.product.restaurant.media', 'items.product.media', 'items.modifiers']));
        });
    }

    public function deleteItem(int $userId, int $itemId): array
    {
        return DB::transaction(function () use ($userId, $itemId): array {
            $item = CartItem::query()
                ->whereKey($itemId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with('cart')
                ->firstOrFail();

            $cart = $item->cart;
            $item->delete();

            $freshCart = $cart->fresh(['items.product.restaurant.media', 'items.product.media', 'items.modifiers']);

            if ($freshCart && $freshCart->items->isEmpty()) {
                $freshCart->delete();

                return [
                    'id' => null,
                    'merchant' => null,
                    'items' => [],
                    'merchantGroups' => [],
                    'amounts' => ['subtotal' => 0.0, 'total' => 0.0],
                ];
            }

            return $this->toPayload($freshCart ?? $cart);
        });
    }

    private function resolveActiveCart(int $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    private function validatedModifiers(Product $product, array $modifierIds)
    {
        $modifierIds = $this->normalizeModifierIds($modifierIds);

        if ($modifierIds === []) {
            return collect();
        }

        $allowedIds = $product->modifierGroups
            ->flatMap(fn ($group) => $group->modifiers->pluck('id'))
            ->unique()
            ->values()
            ->all();

        if (array_diff($modifierIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'modifierIds' => ['Some modifiers are not allowed for this product.'],
            ]);
        }

        return Modifier::query()->whereIn('id', $modifierIds)->get();
    }

    private function normalizeModifierIds(array $modifierIds): array
    {
        $modifierIds = array_values(array_unique(array_map('intval', $modifierIds)));
        sort($modifierIds);

        return $modifierIds;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note ?? ''));

        return $note === '' ? null : $note;
    }

    private function calculateUnitPrice(Product $product, $modifiers): float
    {
        $modifierTotal = (float) $modifiers->sum(fn (Modifier $modifier): float => (float) ($modifier->price ?? 0));
        $basePrice = (float) ($product->discounted_price ?? $product->price ?? 0);

        return $basePrice + $modifierTotal;
    }

    private function findMatchingItem(
        int $cartId,
        int $productId,
        array $modifierIds,
        ?int $substituteProductId,
        ?string $note,
        ?int $exceptItemId = null,
    ): ?CartItem {
        $query = CartItem::query()
            ->where('cart_id', $cartId)
            ->where('product_id', $productId)
            ->where('substitute_product_id', $substituteProductId)
            ->where('special_instructions', $this->normalizeNote($note))
            ->with('modifiers')
            ->lockForUpdate();

        if ($exceptItemId !== null) {
            $query->whereKeyNot($exceptItemId);
        }

        return $query->get()->first(function (CartItem $item) use ($modifierIds): bool {
            return $this->normalizeModifierIds($item->modifiers->pluck('id')->all()) === $this->normalizeModifierIds($modifierIds);
        });
    }

    private function toPayload(Cart $cart): array
    {
        $groupedItems = $cart->items->groupBy(fn (CartItem $item): int => (int) $item->product?->restaurant_id);

        $merchantGroups = $groupedItems->map(function ($items, int $restaurantId): array {
            $restaurant = $items->first()?->product?->restaurant;

            $mappedItems = $items->map(fn (CartItem $item): array => [
                'id' => $item->id,
                'productId' => $item->product_id,
                'name' => $item->product?->name,
                'primaryImageUrl' => $item->product !== null
                    ? ($item->product->getFirstMediaUrl('primary-image') ?: null)
                    : null,
                'images' => $item->product !== null
                    ? $item->product->getMedia('images')->map(fn ($media) => $media->getUrl())->values()->all()
                    : [],
                'quantity' => $item->quantity,
                'unitPrice' => (float) ($item->unit_price ?? 0),
                'totalPrice' => (float) ($item->total_price ?? 0),
                'modifierIds' => $item->modifiers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'substituteProductId' => $item->substitute_product_id,
                'note' => $item->special_instructions,
            ])->values();

            $subtotal = (float) $mappedItems->sum('totalPrice');

            return [
                'merchant' => [
                    'id' => $restaurant?->id,
                    'name' => $restaurant?->name,
                    'primaryImageUrl' => $restaurant !== null
                        ? ($restaurant->getFirstMediaUrl('primary-image') ?: null)
                        : null,
                    'bannerImageUrl' => $restaurant !== null
                        ? ($restaurant->getFirstMediaUrl('banner-image') ?: null)
                        : null,
                ],
                'items' => $mappedItems->all(),
                'amounts' => [
                    'subtotal' => round($subtotal, 2),
                    'total' => round($subtotal, 2),
                ],
            ];
        })->values();

        $legacyItems = $merchantGroups
            ->flatMap(fn (array $group) => $group['items'])
            ->values();

        $legacyMerchant = null;
        if ($merchantGroups->count() === 1) {
            $legacyMerchant = $merchantGroups->first()['merchant'] ?? null;
        }

        $grandSubtotal = (float) $merchantGroups->sum(fn (array $group): float => $group['amounts']['subtotal']);

        return [
            'id' => $cart->id,
            'merchant' => $legacyMerchant,
            'items' => $legacyItems->all(),
            'merchantGroups' => $merchantGroups->all(),
            'productsCount' => (int) $legacyItems->sum('quantity'),
            'amounts' => [
                'subtotal' => round($grandSubtotal, 2),
                'total' => round($grandSubtotal, 2),
            ],
        ];
    }
}
