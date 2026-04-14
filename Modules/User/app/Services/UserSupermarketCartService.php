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
        $query = SmCart::query()
            ->where('user_id', $userId)
            ->with(['store', 'items.product']);

        $cart = $query->latest()->first();

        if (! $cart) {
            return [
                'id' => null,
                'merchant' => null,
                'items' => [],
                'amounts' => [
                    'subtotal' => 0.0,
                    'total' => 0.0,
                ],
            ];
        }

        return $this->toPayload($cart);
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

            $merchantId = (int) $product->store_id;
            $cart = $this->resolveActiveCart($userId, $merchantId);

            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            SmCartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);

            return $this->toPayload($cart->fresh(['store', 'items.product']));
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

            $cart = $this->resolveActiveCart($userId, $storeId);

            foreach ($mergedQuantities as $productId => $quantity) {
                $product = $products->get($productId);
                $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);
                SmCartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]);
            }

            return $this->toPayload($cart->fresh(['store', 'items.product']));
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
                ->firstOrFail();

            $unitPrice = (float) ($item->product->discounted_price ?? $item->product->price ?? 0);
            $item->update([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);

            return $this->toPayload($item->cart->fresh(['store', 'items.product']));
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

            return $this->toPayload($cart->fresh(['store', 'items.product']));
        });
    }

    private function resolveActiveCart(int $userId, int $storeId): SmCart
    {
        $activeCart = SmCart::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $activeCart) {
            return SmCart::create([
                'user_id' => $userId,
                'store_id' => $storeId,
            ]);
        }

        SmCart::query()
            ->where('user_id', $userId)
            ->where('id', '!=', $activeCart->id)
            ->delete();

        if ((int) $activeCart->store_id !== $storeId) {
            $activeCart->items()->delete();
            $activeCart->update([
                'store_id' => $storeId,
            ]);
        }

        return $activeCart->fresh() ?? $activeCart;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(SmCart $cart): array
    {
        $items = $cart->items->map(fn (SmCartItem $item): array => [
            'id' => $item->id,
            'productId' => $item->product_id,
            'name' => $item->product?->name,
            'quantity' => $item->quantity,
            'unitPrice' => (float) ($item->unit_price ?? 0),
            'totalPrice' => round((float) ($item->unit_price ?? 0) * (int) $item->quantity, 2),
        ])->values();

        $subtotal = (float) $items->sum('totalPrice');

        return [
            'id' => $cart->id,
            'merchant' => [
                'id' => $cart->store?->id,
                'name' => $cart->store?->name,
            ],
            'items' => $items->all(),
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
            ],
        ];
    }
}
