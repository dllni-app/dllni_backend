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
    /**
     * @return array<string, mixed>
     */
    public function show(int $userId, ?int $merchantId = null): array
    {
        $query = Cart::query()
            ->where('user_id', $userId)
            ->with(['restaurant', 'items.product', 'items.modifiers']);

        if ($merchantId !== null) {
            $query->where('restaurant_id', $merchantId);
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
     * @param  array<int>  $modifierIds
     * @return array<string, mixed>
     */
    public function addItem(
        int $userId,
        int $merchantId,
        int $productId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $note = null,
    ): array {
        return DB::transaction(function () use ($userId, $merchantId, $productId, $quantity, $modifierIds, $substituteProductId, $note): array {
            $product = Product::query()
                ->with(['modifierGroups.modifiers'])
                ->findOrFail($productId);

            if ((int) $product->restaurant_id !== $merchantId) {
                throw ValidationException::withMessages([
                    'merchantId' => ['The selected product does not belong to the given merchant.'],
                ]);
            }

            $cart = Cart::query()->firstOrCreate([
                'user_id' => $userId,
                'restaurant_id' => $merchantId,
            ]);

            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $modifierTotal = (float) $modifiers->sum(fn (Modifier $modifier): float => (float) ($modifier->price ?? 0));
            $basePrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            $unitPrice = $basePrice + $modifierTotal;

            $item = CartItem::query()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'substitute_product_id' => $substituteProductId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $note,
            ]);

            if ($modifiers->isNotEmpty()) {
                $item->modifiers()->attach(
                    $modifiers->mapWithKeys(fn (Modifier $modifier): array => [
                        $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
                    ])->all()
                );
            }

            return $this->toPayload($cart->fresh(['restaurant', 'items.product', 'items.modifiers']));
        });
    }

    /**
     * @param  array<int>  $modifierIds
     * @return array<string, mixed>
     */
    public function updateItem(
        int $userId,
        int $itemId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $note = null,
    ): array {
        return DB::transaction(function () use ($userId, $itemId, $quantity, $modifierIds, $substituteProductId, $note): array {
            $item = CartItem::query()
                ->whereKey($itemId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with(['product.modifierGroups.modifiers', 'cart'])
                ->firstOrFail();

            $product = $item->product;
            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $modifierTotal = (float) $modifiers->sum(fn (Modifier $modifier): float => (float) ($modifier->price ?? 0));
            $basePrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            $unitPrice = $basePrice + $modifierTotal;

            $item->update([
                'quantity' => $quantity,
                'substitute_product_id' => $substituteProductId,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $note,
            ]);

            $item->modifiers()->detach();
            if ($modifiers->isNotEmpty()) {
                $item->modifiers()->attach(
                    $modifiers->mapWithKeys(fn (Modifier $modifier): array => [
                        $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
                    ])->all()
                );
            }

            return $this->toPayload($item->cart->fresh(['restaurant', 'items.product', 'items.modifiers']));
        });
    }

    /**
     * @return array<string, mixed>
     */
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

            return $this->toPayload($cart->fresh(['restaurant', 'items.product', 'items.modifiers']));
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, Modifier>
     */
    private function validatedModifiers(Product $product, array $modifierIds)
    {
        $modifierIds = array_values(array_unique(array_map('intval', $modifierIds)));

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
     * @return array<string, mixed>
     */
    private function toPayload(Cart $cart): array
    {
        $items = $cart->items->map(fn (CartItem $item): array => [
            'id' => $item->id,
            'productId' => $item->product_id,
            'name' => $item->product?->name,
            'quantity' => $item->quantity,
            'unitPrice' => (float) ($item->unit_price ?? 0),
            'totalPrice' => (float) ($item->total_price ?? 0),
            'modifierIds' => $item->modifiers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'substituteProductId' => $item->substitute_product_id,
            'note' => $item->special_instructions,
        ])->values();

        $subtotal = (float) $items->sum('totalPrice');

        return [
            'id' => $cart->id,
            'merchant' => [
                'id' => $cart->restaurant?->id,
                'name' => $cart->restaurant?->name,
            ],
            'items' => $items->all(),
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
            ],
        ];
    }
}

