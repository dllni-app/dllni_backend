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

final class RestaurantCartService
{
    /**
     * @param  array<int>  $modifierIds
     * @return array{cart: Cart, item: CartItem}
     */
    public function addProductToCart(
        int $userId,
        int $productId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $specialInstructions = null,
    ): array {
        return DB::transaction(function () use ($userId, $productId, $quantity, $modifierIds, $substituteProductId, $specialInstructions) {
            $product = Product::query()
                ->with(['modifierGroups.modifiers'])
                ->findOrFail($productId);

            $cart = Cart::firstOrCreate([
                'user_id' => $userId,
            ]);

            $modifiers = $this->validatedModifiersForProduct($product, $modifierIds);
            $modifiersTotal = (float) $modifiers->sum(fn (Modifier $m) => (float) ($m->price ?? 0));

            $basePrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            $unitPrice = $basePrice + $modifiersTotal;
            $totalPrice = $unitPrice * $quantity;

            $item = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'substitute_product_id' => $substituteProductId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'special_instructions' => $specialInstructions,
            ]);

            if ($modifiers->isNotEmpty()) {
                $rows = $modifiers->map(fn (Modifier $modifier) => [
                    'cart_item_id' => $item->id,
                    'modifier_id' => $modifier->id,
                    'price' => (float) ($modifier->price ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                DB::table('cart_item_modifier')->insert($rows);
            }

            return [
                'cart' => $cart,
                'item' => $item,
            ];
        });
    }

    /**
     * @param  array<int>  $modifierIds
     * @return Collection<int, Modifier>
     */
    private function validatedModifiersForProduct(Product $product, array $modifierIds): Collection
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

        $invalidIds = array_values(array_diff($modifierIds, $allowedIds));

        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'modifierIds' => ['Some modifiers are not allowed for this product.'],
            ]);
        }

        return Modifier::query()
            ->whereIn('id', $modifierIds)
            ->orderBy('sort_order')
            ->get();
    }
}
