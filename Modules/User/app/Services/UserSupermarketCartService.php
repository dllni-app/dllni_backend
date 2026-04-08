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
    public function show(int $userId, ?int $merchantId = null): array
    {
        $query = SmCart::query()
            ->where('user_id', $userId)
            ->with(['store', 'items.product']);

        if ($merchantId !== null) {
            $query->where('store_id', $merchantId);
        }

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
    public function addItem(int $userId, int $merchantId, int $productId, int $quantity): array
    {
        return DB::transaction(function () use ($userId, $merchantId, $productId, $quantity): array {
            $product = SmProduct::query()->findOrFail($productId);

            if ((int) $product->store_id !== $merchantId) {
                throw ValidationException::withMessages([
                    'merchantId' => ['The selected product does not belong to the given merchant.'],
                ]);
            }

            $cart = SmCart::query()->firstOrCreate([
                'user_id' => $userId,
                'store_id' => $merchantId,
            ]);

            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            SmCartItem::query()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);

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

