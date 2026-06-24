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
    /**
     * @return array<string, mixed>
     */
    public function show(int $userId): array
    {
        $cart = SmCart::query()
            ->where('user_id', $userId)
            ->with(['items.product.store'])
            ->first();

        if (! $cart) {
            return $this->emptyCartPayload();
        }

        return $this->toPayload($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(int $userId, int $productId, int $quantity): array
    {
        return DB::transaction(function () use ($userId, $productId, $quantity): array {
            $product = SmProduct::query()
                ->lockForUpdate()
                ->findOrFail($productId);

            $cart = $this->resolveActiveCart($userId);
            $existingItem = SmCartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            $targetQuantity = (int) ($existingItem?->quantity ?? 0) + $quantity;
            $this->validateProductForCart($product, $targetQuantity);
            $unitPrice = $this->unitPriceFor($product);

            if ($existingItem) {
                $existingItem->update([
                    'quantity' => $targetQuantity,
                    'unit_price' => $unitPrice,
                ]);
            } else {
                SmCartItem::query()->create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]);
            }

            return $this->toPayload($cart->fresh(['items.product.store']));
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
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $cart = $this->resolveActiveCart($userId);
            $existingItems = SmCartItem::query()
                ->where('cart_id', $cart->id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            foreach ($mergedQuantities as $productId => $quantity) {
                $product = $products->get($productId);
                if (! $product || (int) $product->store_id !== $storeId) {
                    throw ValidationException::withMessages([
                        'storeId' => ['One or more products do not belong to the selected store.'],
                    ]);
                }

                $targetQuantity = (int) ($existingItems->get($productId)?->quantity ?? 0) + $quantity;
                $this->validateProductForCart($product, $targetQuantity);
            }

            foreach ($mergedQuantities as $productId => $quantity) {
                $product = $products->get($productId);
                $existingItem = $existingItems->get($productId);
                $unitPrice = $this->unitPriceFor($product);

                if ($existingItem) {
                    $existingItem->update([
                        'quantity' => (int) $existingItem->quantity + $quantity,
                        'unit_price' => $unitPrice,
                    ]);
                } else {
                    SmCartItem::query()->create([
                        'cart_id' => $cart->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                    ]);
                }
            }

            return $this->toPayload($cart->fresh(['items.product.store']));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function updateItem(int $userId, int $itemId, int $quantity): array
    {
        return DB::transaction(function () use ($userId, $itemId, $quantity): array {
            $item = SmCartItem::query()
                ->whereKey($itemId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with(['product', 'cart'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $item->product) {
                throw ValidationException::withMessages([
                    'productId' => ['The selected product no longer exists.'],
                ]);
            }

            $this->validateProductForCart($item->product, $quantity);
            $item->update([
                'quantity' => $quantity,
                'unit_price' => $this->unitPriceFor($item->product),
            ]);

            return $this->toPayload($item->cart->fresh(['items.product.store']));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteItem(int $userId, int $itemId): array
    {
        return DB::transaction(function () use ($userId, $itemId): array {
            $item = SmCartItem::query()
                ->whereKey($itemId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with('cart')
                ->firstOrFail();

            $cart = $item->cart;
            $item->delete();

            $freshCart = $cart->fresh(['items.product.store']);

            if ($freshCart && $freshCart->items->isEmpty()) {
                $freshCart->delete();

                return $this->emptyCartPayload();
            }

            return $this->toPayload($freshCart ?? $cart);
        });
    }

    private function resolveActiveCart(int $userId): SmCart
    {
        return SmCart::query()->firstOrCreate(['user_id' => $userId]);
    }

    private function unitPriceFor(SmProduct $product): float
    {
        return (float) ($product->discounted_price ?? $product->price ?? 0);
    }

    private function validateProductForCart(SmProduct $product, int $requestedQuantity): void
    {
        if (! $product->store_id) {
            throw ValidationException::withMessages([
                'productId' => ['The selected product is not linked to a store.'],
            ]);
        }

        if (! $product->is_available) {
            throw ValidationException::withMessages([
                'productId' => ["Product {$product->id} is not available."],
            ]);
        }

        if ((int) $product->stock_quantity < $requestedQuantity) {
            throw ValidationException::withMessages([
                'quantity' => ["Requested quantity for product {$product->id} exceeds available stock."],
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
            'merchantGroups' => [],
            'isMultiMerchant' => false,
            'checkout' => [
                'canPlaceOrder' => false,
                'blockedReason' => 'empty_cart',
                'message' => 'Cart is empty.',
            ],
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
        $groupedItems = $cart->items->groupBy(fn (SmCartItem $item): int => (int) $item->product?->store_id);

        $merchantGroups = $groupedItems->map(function ($items): array {
            $store = $items->first()?->product?->store;

            $mappedItems = $items->map(function (SmCartItem $item) use ($store): array {
                $product = $item->product;
                $availableStock = (int) ($product?->stock_quantity ?? 0);
                $isAvailableInStock = $product !== null
                    && (bool) $product->is_available
                    && $availableStock >= (int) $item->quantity;

                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'merchantId' => $store?->id,
                    'merchantName' => $store?->name,
                    'name' => $product?->name,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (float) ($item->unit_price ?? 0),
                    'totalPrice' => round((float) ($item->unit_price ?? 0) * (int) $item->quantity, 2),
                    'isAvailableInStock' => $isAvailableInStock,
                    'availableStock' => $availableStock,
                ];
            })->values();

            $subtotal = (float) $mappedItems->sum('totalPrice');

            return [
                'merchant' => [
                    'id' => $store?->id,
                    'name' => $store?->name,
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

        $isMultiMerchant = $merchantGroups->count() > 1;
        $primaryMerchant = $isMultiMerchant ? null : ($merchantGroups->first()['merchant'] ?? null);
        $grandSubtotal = (float) $merchantGroups->sum(fn (array $group): float => $group['amounts']['subtotal']);
        $hasUnavailableItems = $legacyItems->contains(fn (array $item): bool => ! (bool) $item['isAvailableInStock']);

        return [
            'id' => $cart->id,
            'merchant' => $primaryMerchant,
            'items' => $legacyItems->all(),
            'merchantGroups' => $merchantGroups->all(),
            'isMultiMerchant' => $isMultiMerchant,
            'checkout' => [
                'canPlaceOrder' => ! $hasUnavailableItems,
                'blockedReason' => $hasUnavailableItems ? 'out_of_stock' : null,
                'message' => $hasUnavailableItems
                    ? 'One or more supermarket cart items are unavailable or exceed available stock.'
                    : null,
            ],
            'amounts' => [
                'subtotal' => round($grandSubtotal, 2),
                'total' => round($grandSubtotal, 2),
            ],
        ];
    }
}
