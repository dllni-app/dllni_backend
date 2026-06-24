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
    private const CART_RELATIONS = [
        'restaurant.media',
        'items.product.media',
        'items.modifiers',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return Cart::query()
            ->where('user_id', $userId)
            ->whereNotNull('restaurant_id')
            ->with(self::CART_RELATIONS)
            ->latest()
            ->get()
            ->filter(fn (Cart $cart): bool => $cart->items->isNotEmpty())
            ->map(fn (Cart $cart): array => $this->toPayload($this->normalizeCart($cart)))
            ->values()
            ->all();
    }

    public function show(int $userId, int $cartId): array
    {
        $cart = Cart::query()
            ->whereKey($cartId)
            ->where('user_id', $userId)
            ->whereNotNull('restaurant_id')
            ->with(self::CART_RELATIONS)
            ->firstOrFail();

        return $this->toPayload($this->normalizeCart($cart));
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

            $cart = $this->resolveActiveCart($userId, (int) $product->restaurant_id);
            $cart = $this->normalizeCart($cart);
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
                ->lockForUpdate()
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
        int $cartId,
        int $itemId,
        int $quantity,
        ?array $modifierIds = null,
        ?int $substituteProductId = null,
        ?string $note = null,
        bool $replaceSubstituteProduct = true,
        bool $replaceNote = true,
    ): array {
        return DB::transaction(function () use ($userId, $cartId, $itemId, $quantity, $modifierIds, $substituteProductId, $note, $replaceSubstituteProduct, $replaceNote): array {
            $item = CartItem::query()
                ->whereKey($itemId)
                ->where('cart_id', $cartId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with(['product.modifierGroups.modifiers', 'cart', 'modifiers'])
                ->lockForUpdate()
                ->firstOrFail();

            $product = $item->product;

            $this->assertProductBelongsToCartRestaurant($item->cart, $product);

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
                ->lockForUpdate()
                ->first();

            if ($targetItem) {
                $mergedQuantity = (int) $targetItem->quantity + $quantity;

                $targetItem->fill([
                    'quantity' => $mergedQuantity,
                    'substitute_product_id' => $nextSubstituteProductId,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $mergedQuantity,
                    'special_instructions' => $nextNote,
                    'signature_hash' => $signatureHash,
                ]);
                $targetItem->save();
                $this->syncModifiers($targetItem, $modifiers);
                $item->delete();

                return $this->toPayload($this->normalizeCart($targetItem->cart));
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

            return $this->toPayload($this->normalizeCart($item->cart));
        });
    }

    public function deleteItem(int $userId, int $cartId, int $itemId): array
    {
        return DB::transaction(function () use ($userId, $cartId, $itemId): array {
            $item = CartItem::query()
                ->whereKey($itemId)
                ->where('cart_id', $cartId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with('cart')
                ->lockForUpdate()
                ->firstOrFail();

            $cart = $item->cart;
            $item->delete();

            $freshCart = $this->normalizeCart($cart);

            if ($freshCart->items->isEmpty()) {
                $freshCart->delete();

                return $this->emptyCartPayload();
            }

            return $this->toPayload($freshCart);
        });
    }

    private function resolveActiveCart(int $userId, int $restaurantId): Cart
    {
        return Cart::firstOrCreate([
            'user_id' => $userId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    private function normalizeCart(Cart $cart): Cart
    {
        return DB::transaction(function () use ($cart): Cart {
            $lockedCart = Cart::query()
                ->whereKey($cart->id)
                ->lockForUpdate()
                ->firstOrFail();

            $items = CartItem::query()
                ->where('cart_id', $lockedCart->id)
                ->with(['product', 'modifiers'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                return $lockedCart->fresh(self::CART_RELATIONS) ?? $lockedCart;
            }

            $rows = $items->map(function (CartItem $item) use ($lockedCart): array {
                $this->assertProductBelongsToCartRestaurant($lockedCart, $item->product);

                $modifierIds = $item->modifiers
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $signatureHash = $this->signatureHash(
                    productId: (int) $item->product_id,
                    modifierIds: $modifierIds,
                    substituteProductId: $item->substitute_product_id === null ? null : (int) $item->substitute_product_id,
                    note: $this->normalizeNote($item->special_instructions),
                );

                return [
                    'item' => $item,
                    'signature_hash' => $signatureHash,
                    'quantity' => max(1, (int) $item->quantity),
                ];
            });

            foreach ($rows->groupBy('signature_hash') as $signatureHash => $group) {
                $keeperRow = $group->first(
                    fn (array $row): bool => (string) $row['item']->signature_hash === (string) $signatureHash,
                ) ?? $group->first();

                /** @var CartItem $keeper */
                $keeper = $keeperRow['item'];
                $mergedQuantity = (int) $group->sum('quantity');
                $unitPrice = (float) ($keeper->unit_price ?? 0);
                $normalizedNote = $this->normalizeNote($keeper->special_instructions);

                foreach ($group as $row) {
                    /** @var CartItem $item */
                    $item = $row['item'];

                    if ((int) $item->id === (int) $keeper->id) {
                        continue;
                    }

                    $item->modifiers()->detach();
                    $item->delete();
                }

                $keeper->forceFill([
                    'quantity' => $mergedQuantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $mergedQuantity,
                    'signature_hash' => $signatureHash,
                    'special_instructions' => $normalizedNote,
                ])->save();
            }

            return $lockedCart->fresh(self::CART_RELATIONS) ?? $lockedCart;
        });
    }

    private function assertProductBelongsToCartRestaurant(Cart $cart, ?Product $product): void
    {
        if (! $product || (int) $product->restaurant_id !== (int) $cart->restaurant_id) {
            throw ValidationException::withMessages([
                'cart' => ['Cart items must belong to the same restaurant as the cart.'],
            ]);
        }
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
        $freshCart = $this->normalizeCart($cart);
        $responseItem = $freshCart->items
            ->first(fn (CartItem $cartItem): bool => (string) $cartItem->signature_hash === (string) $item->signature_hash)
            ?? $item;

        return [
            'message' => $operation === 'created' ? 'Item added to cart.' : 'Item updated in cart.',
            'cartId' => $freshCart->id,
            'merchantId' => $freshCart->restaurant_id,
            'itemId' => $responseItem->id,
            'quantity' => (int) $responseItem->quantity,
            'cartProductsCount' => (int) $freshCart->items->sum('quantity'),
            'operation' => $operation,
            'cart' => $this->toPayload($freshCart),
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
            'productsCount' => 0,
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
        $mappedItems = $cart->items->map(fn (CartItem $item): array => [
            'id' => $item->id,
            'productId' => $item->product_id,
            'name' => $item->product?->name,
            'primaryImageUrl' => $item->product !== null
                ? ($item->product->getFirstMediaUrl('primary-image') ?: null)
                : null,
            'images' => $item->product !== null
                ? $item->product->getMedia('images')->map(fn ($media) => $media->getUrl())->values()->all()
                : [],
            'quantity' => (int) $item->quantity,
            'unitPrice' => (float) ($item->unit_price ?? 0),
            'totalPrice' => (float) ($item->total_price ?? 0),
            'modifierIds' => $item->modifiers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'substituteProductId' => $item->substitute_product_id,
            'note' => $item->special_instructions,
        ])->values();

        $subtotal = (float) $mappedItems->sum('totalPrice');
        $restaurant = $cart->restaurant;

        return [
            'id' => $cart->id,
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
            'productsCount' => (int) $mappedItems->sum('quantity'),
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
            ],
        ];
    }
}
