<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Collection;
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
            return $this->emptyCartPayload();
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
        string $quantityMode = 'increment',
    ): array {
        return DB::transaction(function () use ($userId, $productId, $quantity, $modifierIds, $substituteProductId, $note, $quantityMode): array {
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
            $normalizedNote = $this->normalizeNote($note);
            $signatureHash = $this->signatureHash(
                productId: (int) $product->id,
                modifierIds: $modifierIds,
                substituteProductId: $substituteProductId,
                note: $normalizedNote,
            );

            $unitPrice = $this->unitPrice($product, $modifiers);

            $item = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('signature_hash', $signatureHash)
                ->with(['cart', 'modifiers'])
                ->first();

            $operation = 'created';

            if ($item) {
                $quantity = $quantityMode === 'set'
                    ? $quantity
                    : ((int) $item->quantity + $quantity);

                $operation = 'updated';
            } else {
                $item = new CartItem([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'substitute_product_id' => $substituteProductId,
                    'special_instructions' => $normalizedNote,
                    'signature_hash' => $signatureHash,
                ]);
            }

            $item->fill([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'substitute_product_id' => $substituteProductId,
                'special_instructions' => $normalizedNote,
                'signature_hash' => $signatureHash,
            ]);
            $item->save();

            $this->syncModifiers($item, $modifiers);

            return $this->cartMutationResponse(
                cart: $cart,
                item: $item,
                operation: $operation,
            );
        });
    }

    /**
     * @param  array<int>|null  $modifierIds
     * @return array<string, mixed>
     */
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
                ->firstOrFail();

            $product = $item->product;

            $modifiers = $modifierIds === null
                ? $item->modifiers
                : $this->validatedModifiers($product, $this->normalizeModifierIds($modifierIds));

            $normalizedModifierIds = $modifiers
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $nextSubstituteProductId = $replaceSubstituteProduct
                ? $substituteProductId
                : ($item->substitute_product_id === null ? null : (int) $item->substitute_product_id);

            $nextNote = $replaceNote
                ? $this->normalizeNote($note)
                : $this->normalizeNote($item->special_instructions);

            $signatureHash = $this->signatureHash(
                productId: (int) $product->id,
                modifierIds: $normalizedModifierIds,
                substituteProductId: $nextSubstituteProductId,
                note: $nextNote,
            );

            $unitPrice = $this->unitPrice($product, $modifiers);

            $targetItem = CartItem::query()
                ->where('cart_id', $item->cart_id)
                ->where('signature_hash', $signatureHash)
                ->whereKeyNot($item->id)
                ->with(['cart', 'modifiers'])
                ->first();

            if ($targetItem) {
                $targetItem->fill([
                    'quantity' => $quantity,
                    'substitute_product_id' => $nextSubstituteProductId,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $quantity,
                    'special_instructions' => $nextNote,
                    'signature_hash' => $signatureHash,
                ]);
                $targetItem->save();
                $this->syncModifiers($targetItem, $modifiers);
                $item->delete();

                return $this->toPayload($targetItem->cart->fresh(['items.product.restaurant.media', 'items.product.media', 'items.modifiers']));
            }

            $item->fill([
                'quantity' => $quantity,
                'substitute_product_id' => $nextSubstituteProductId,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $nextNote,
                'signature_hash' => $signatureHash,
            ]);
            $item->save();

            if ($modifierIds !== null) {
                $this->syncModifiers($item, $modifiers);
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

                return $this->emptyCartPayload();
            }

            return $this->toPayload($freshCart ?? $cart);
        });
    }

    private function resolveActiveCart(int $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    /**
     * @param  array<int>  $modifierIds
     * @return Collection<int, Modifier>
     */
    private function validatedModifiers(Product $product, array $modifierIds): Collection
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

    /**
     * @param  array<int>  $modifierIds
     * @return array<int>
     */
    private function normalizeModifierIds(array $modifierIds): array
    {
        $modifierIds = array_values(array_unique(array_map('intval', $modifierIds)));
        sort($modifierIds);

        return $modifierIds;
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($note));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<int>  $modifierIds
     */
    private function signatureHash(int $productId, array $modifierIds, ?int $substituteProductId, ?string $note): string
    {
        return hash('sha256', json_encode([
            'product_id' => $productId,
            'modifier_ids' => $this->normalizeModifierIds($modifierIds),
            'substitute_product_id' => $substituteProductId,
            'note' => $this->normalizeNote($note),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Collection<int, Modifier>  $modifiers
     */
    private function unitPrice(Product $product, Collection $modifiers): float
    {
        $modifierTotal = (float) $modifiers->sum(fn (Modifier $modifier): float => (float) ($modifier->price ?? 0));
        $basePrice = (float) ($product->discounted_price ?? $product->price ?? 0);

        return $basePrice + $modifierTotal;
    }

    /**
     * @param  Collection<int, Modifier>  $modifiers
     */
    private function syncModifiers(CartItem $item, Collection $modifiers): void
    {
        $item->modifiers()->sync(
            $modifiers->mapWithKeys(fn (Modifier $modifier): array => [
                $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
            ])->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cartMutationResponse(Cart $cart, CartItem $item, string $operation): array
    {
        $freshCart = $cart->fresh(['items.product.restaurant.media', 'items.product.media', 'items.modifiers']);
        $cartProductsCount = $freshCart ? (int) $freshCart->items->sum('quantity') : (int) $item->quantity;

        return [
            'message' => $operation === 'created' ? 'Item added to cart.' : 'Item updated in cart.',
            'cartId' => $cart->id,
            'itemId' => $item->id,
            'quantity' => (int) $item->quantity,
            'cartProductsCount' => $cartProductsCount,
            'operation' => $operation,
            'cart' => $freshCart ? $this->toPayload($freshCart) : $this->emptyCartPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCartPayload(): array
    {
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

    /**
     * @return array<string, mixed>
     */
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
