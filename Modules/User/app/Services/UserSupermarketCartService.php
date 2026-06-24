<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmProduct;

final class UserSupermarketCartService
{
    private const CART_RELATIONS = [
        'store',
        'items.product.store',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return SmCart::query()
            ->where('user_id', $userId)
            ->whereNotNull('store_id')
            ->with(self::CART_RELATIONS)
            ->latest()
            ->get()
            ->filter(fn (SmCart $cart): bool => $cart->items->isNotEmpty())
            ->map(fn (SmCart $cart): array => $this->toPayload($this->normalizeCart($cart)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $userId, int $cartId): array
    {
        $cart = SmCart::query()
            ->whereKey($cartId)
            ->where('user_id', $userId)
            ->whereNotNull('store_id')
            ->with(self::CART_RELATIONS)
            ->firstOrFail();

        return $this->toPayload($this->normalizeCart($cart));
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(int $userId, int $productId, int $quantity): array
    {
        return DB::transaction(function () use ($userId, $productId, $quantity): array {
            $product = SmProduct::query()->findOrFail($productId);

            if (! $product->store_id) {
                throw ValidationException::withMessages([
                    'productId' => ['The selected product is not linked to a store.'],
                ]);
            }

            $cart = $this->normalizeCart($this->resolveActiveCart($userId, (int) $product->store_id));
            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);

            $item = SmCartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($item) {
                $item->update([
                    'quantity' => (int) $item->quantity + $quantity,
                    'unit_price' => $unitPrice,
                ]);
            } else {
                $item = SmCartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]);
            }

            return $this->toPayload($this->normalizeCart($item->cart ?? $cart));
        });
    }

    /**
     * @param  array<int, array{productId: int, quantity: int}>  $lines
     * @return array<string, mixed>
     */
    public function addLinesForStore(int $userId, int $storeId, array $lines): array
    {
        return DB::transaction(function () use ($userId, $storeId, $lines): array {
            if ($lines === []) {
                throw ValidationException::withMessages([
                    'lines' => ['No cart lines were provided.'],
                ]);
            }

            $mergedQuantities = [];
            foreach ($lines as $line) {
                $pid = (int) $line['productId'];
                $qty = max(1, (int) $line['quantity']);
                $mergedQuantities[$pid] = ($mergedQuantities[$pid] ?? 0) + $qty;
            }

            $productIds = array_keys($mergedQuantities);
            $products = SmProduct::query()
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            foreach ($mergedQuantities as $productId => $quantity) {
                $product = $products->get($productId);
                if (! $product || (int) $product->store_id !== $storeId) {
                    throw ValidationException::withMessages([
                        'storeId' => ['One or more products do not belong to the selected store.'],
                    ]);
                }
                if (! $product->is_available) {
                    throw ValidationException::withMessages([
                        'productId' => ["Product {$productId} is not available."],
                    ]);
                }
            }

            $cart = $this->normalizeCart($this->resolveActiveCart($userId, $storeId));

            foreach ($mergedQuantities as $productId => $quantity) {
                $product = $products->get($productId);
                $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);

                $item = SmCartItem::query()
                    ->where('cart_id', $cart->id)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($item) {
                    $item->update([
                        'quantity' => (int) $item->quantity + $quantity,
                        'unit_price' => $unitPrice,
                    ]);
                } else {
                    SmCartItem::create([
                        'cart_id' => $cart->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                    ]);
                }
            }

            return $this->toPayload($this->normalizeCart($cart));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function updateItem(int $userId, int $cartId, int $itemId, int $quantity): array
    {
        return DB::transaction(function () use ($userId, $cartId, $itemId, $quantity): array {
            $item = SmCartItem::query()
                ->whereKey($itemId)
                ->where('cart_id', $cartId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with(['product', 'cart'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertProductBelongsToCartStore($item->cart, $item->product);

            $unitPrice = (float) ($item->product->discounted_price ?? $item->product->price ?? 0);
            $item->update([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);

            return $this->toPayload($this->normalizeCart($item->cart));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteItem(int $userId, int $cartId, int $itemId): array
    {
        return DB::transaction(function () use ($userId, $cartId, $itemId): array {
            $item = SmCartItem::query()
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

    private function resolveActiveCart(int $userId, int $storeId): SmCart
    {
        return SmCart::firstOrCreate([
            'user_id' => $userId,
            'store_id' => $storeId,
        ]);
    }

    private function normalizeCart(SmCart $cart): SmCart
    {
        return DB::transaction(function () use ($cart): SmCart {
            $lockedCart = SmCart::query()
                ->whereKey($cart->id)
                ->lockForUpdate()
                ->firstOrFail();

            $items = SmCartItem::query()
                ->where('cart_id', $lockedCart->id)
                ->with('product')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                return $lockedCart->fresh(self::CART_RELATIONS) ?? $lockedCart;
            }

            foreach ($items as $item) {
                $this->assertProductBelongsToCartStore($lockedCart, $item->product);
            }

            foreach ($items->groupBy('product_id') as $productId => $group) {
                /** @var SmCartItem $keeper */
                $keeper = $group->first();
                $mergedQuantity = (int) $group->sum(fn (SmCartItem $item): int => max(1, (int) $item->quantity));
                $unitPrice = (float) ($keeper->product?->discounted_price ?? $keeper->product?->price ?? $keeper->unit_price ?? 0);

                foreach ($group->slice(1) as $item) {
                    $item->delete();
                }

                $keeper->update([
                    'quantity' => $mergedQuantity,
                    'unit_price' => $unitPrice,
                ]);
            }

            return $lockedCart->fresh(self::CART_RELATIONS) ?? $lockedCart;
        });
    }

    private function assertProductBelongsToCartStore(SmCart $cart, ?SmProduct $product): void
    {
        if (! $product || (int) $product->store_id !== (int) $cart->store_id) {
            throw ValidationException::withMessages([
                'cart' => ['Cart items must belong to the same store as the cart.'],
            ]);
        }
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
    private function toPayload(SmCart $cart): array
    {
        $store = $cart->store ?? $cart->items->first()?->product?->store;

        $mappedItems = $cart->items->map(fn (SmCartItem $item): array => [
            'id' => $item->id,
            'productId' => $item->product_id,
            'name' => $item->product?->name,
            'quantity' => (int) $item->quantity,
            'unitPrice' => (float) ($item->unit_price ?? 0),
            'totalPrice' => round((float) ($item->unit_price ?? 0) * (int) $item->quantity, 2),
        ])->values();

        $subtotal = (float) $mappedItems->sum('totalPrice');

        return [
            'id' => $cart->id,
            'merchant' => [
                'id' => $store?->id,
                'name' => $store?->name,
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
