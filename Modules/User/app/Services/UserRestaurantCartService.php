<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\Product;

final class UserRestaurantCartService
{
    /**
     * @return array<string, mixed>
     */
    public function show(int $userId): array
    {
        $cart = Cart::query()
            ->where('user_id', $userId)
            ->with($this->cartRelations())
            ->first();

        if (! $cart) {
            return $this->emptyCartPayload();
        }

        $this->synchronizeCartPrices($cart);

        return $this->toPayload($cart);
    }

    public function synchronizeUserCartPrices(int $userId): void
    {
        $cart = Cart::query()
            ->where('user_id', $userId)
            ->with($this->cartRelations())
            ->first();

        if (! $cart) {
            return;
        }

        $this->synchronizeCartPrices($cart);
    }

    /**
     * @param  array<int>  $modifierIds
     * @return array<string, mixed>
     */
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
                ->with(['modifierGroups.modifiers', 'offers'])
                ->findOrFail($productId);

            if (! $product->restaurant_id) {
                throw ValidationException::withMessages([
                    'productId' => ['The selected product is not linked to a restaurant.'],
                ]);
            }

            $cart = $this->resolveActiveCart($userId);

            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $modifierTotal = $this->modifierTotal($modifiers);
            [, $effectiveProductPrice] = $this->resolveProductPrices($product);
            $unitPrice = round($effectiveProductPrice + $modifierTotal, 2);

            $item = CartItem::query()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'substitute_product_id' => $substituteProductId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
                'special_instructions' => $note,
            ]);

            if ($modifiers->isNotEmpty()) {
                $item->modifiers()->attach(
                    $modifiers->mapWithKeys(fn (Modifier $modifier): array => [
                        $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
                    ])->all()
                );
            }

            $freshCart = $cart->fresh($this->cartRelations());
            if ($freshCart !== null) {
                $this->synchronizeCartPrices($freshCart);
            }

            return [
                'cartId' => $cart->id,
                'itemId' => $item->id,
                'cart' => $this->toPayload($freshCart ?? $cart),
            ];
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
                ->with(['product.modifierGroups.modifiers', 'product.offers', 'cart'])
                ->firstOrFail();

            $product = $item->product;
            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $modifierTotal = $this->modifierTotal($modifiers);
            [, $effectiveProductPrice] = $this->resolveProductPrices($product);
            $unitPrice = round($effectiveProductPrice + $modifierTotal, 2);

            $item->update([
                'quantity' => $quantity,
                'substitute_product_id' => $substituteProductId,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
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

            $freshCart = $item->cart->fresh($this->cartRelations());
            if ($freshCart !== null) {
                $this->synchronizeCartPrices($freshCart);
            }

            return $this->toPayload($freshCart ?? $item->cart);
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

            $freshCart = $cart->fresh($this->cartRelations());

            if ($freshCart && $freshCart->items->isEmpty()) {
                $freshCart->delete();

                return $this->emptyCartPayload();
            }

            if ($freshCart !== null) {
                $this->synchronizeCartPrices($freshCart);
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
     * @param  Collection<int, Modifier>  $modifiers
     */
    private function modifierTotal(Collection $modifiers): float
    {
        return round((float) $modifiers->sum(
            fn (Modifier $modifier): float => (float) ($modifier->pivot?->price ?? $modifier->price ?? 0)
        ), 2);
    }

    private function itemModifierTotal(CartItem $item): float
    {
        return round((float) $item->modifiers->sum(
            fn (Modifier $modifier): float => (float) ($modifier->pivot?->price ?? $modifier->price ?? 0)
        ), 2);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolveProductPrices(Product $product): array
    {
        $price = $product->price !== null ? (float) $product->price : null;
        $discountedPrice = $product->discounted_price !== null
            ? (float) $product->discounted_price
            : null;

        $basePrice = $price ?? $discountedPrice ?? 0.0;

        if ($price !== null && $discountedPrice !== null && $discountedPrice < $price) {
            return [round($price, 2), round($discountedPrice, 2)];
        }

        if (! $product->relationLoaded('offers')) {
            $product->load('offers');
        }

        $activeOffer = $product->offers
            ->filter(function ($offer): bool {
                if (! $offer->is_active) {
                    return false;
                }

                if ($offer->starts_at !== null && now()->lt($offer->starts_at)) {
                    return false;
                }

                if ($offer->ends_at !== null && now()->gte($offer->ends_at)) {
                    return false;
                }

                return true;
            })
            ->sortBy('id')
            ->first();

        if ($activeOffer === null || $activeOffer->discount_value === null) {
            return [round($basePrice, 2), round($basePrice, 2)];
        }

        $discountValue = max(0.0, (float) $activeOffer->discount_value);
        $effectivePrice = match ($activeOffer->discount_type) {
            DiscountType::Percentage => $basePrice * (1 - min(100.0, $discountValue) / 100),
            DiscountType::FixedAmount => max(0.0, $basePrice - $discountValue),
            default => $basePrice,
        };

        return [round($basePrice, 2), round($effectivePrice, 2)];
    }

    private function synchronizeCartPrices(Cart $cart): void
    {
        foreach ($cart->items as $item) {
            $product = $item->product;
            if ($product === null) {
                continue;
            }

            [, $effectiveProductPrice] = $this->resolveProductPrices($product);
            $unitPrice = round($effectiveProductPrice + $this->itemModifierTotal($item), 2);
            $totalPrice = round($unitPrice * (int) $item->quantity, 2);

            if (round((float) ($item->unit_price ?? 0), 2) === $unitPrice
                && round((float) ($item->total_price ?? 0), 2) === $totalPrice) {
                continue;
            }

            $item->update([
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function cartRelations(): array
    {
        return [
            'items.product.restaurant.media',
            'items.product.media',
            'items.product.offers',
            'items.modifiers',
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
                'discount' => 0.0,
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

            $mappedItems = $items->map(function (CartItem $item): array {
                $product = $item->product;
                $modifierTotal = $this->itemModifierTotal($item);
                [$originalProductPrice] = $product !== null
                    ? $this->resolveProductPrices($product)
                    : [(float) ($item->unit_price ?? 0) - $modifierTotal];

                $originalUnitPrice = round($originalProductPrice + $modifierTotal, 2);
                $unitPrice = round((float) ($item->unit_price ?? 0), 2);
                $totalPrice = round((float) ($item->total_price ?? 0), 2);
                $originalTotalPrice = round($originalUnitPrice * (int) $item->quantity, 2);
                $discountAmount = round(max(0.0, $originalTotalPrice - $totalPrice), 2);

                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'name' => $product?->name,
                    'primaryImageUrl' => $product !== null
                        ? ($product->getFirstMediaUrl('primary-image') ?: null)
                        : null,
                    'images' => $product !== null
                        ? $product->getMedia('images')->map(fn ($media) => $media->getUrl())->values()->all()
                        : [],
                    'quantity' => $item->quantity,
                    'unitPrice' => $unitPrice,
                    'originalUnitPrice' => $originalUnitPrice,
                    'discountAmount' => $discountAmount,
                    'totalPrice' => $totalPrice,
                    'originalTotalPrice' => $originalTotalPrice,
                    'modifierIds' => $item->modifiers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'substituteProductId' => $item->substitute_product_id,
                    'note' => $item->special_instructions,
                ];
            })->values();

            $subtotal = round((float) $mappedItems->sum('originalTotalPrice'), 2);
            $total = round((float) $mappedItems->sum('totalPrice'), 2);
            $discount = round(max(0.0, $subtotal - $total), 2);

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
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'total' => $total,
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

        $grandSubtotal = round((float) $merchantGroups->sum(
            fn (array $group): float => (float) $group['amounts']['subtotal']
        ), 2);
        $grandTotal = round((float) $merchantGroups->sum(
            fn (array $group): float => (float) $group['amounts']['total']
        ), 2);
        $grandDiscount = round(max(0.0, $grandSubtotal - $grandTotal), 2);

        return [
            'id' => $cart->id,
            'merchant' => $legacyMerchant,
            'items' => $legacyItems->all(),
            'merchantGroups' => $merchantGroups->all(),
            'amounts' => [
                'subtotal' => $grandSubtotal,
                'discount' => $grandDiscount,
                'total' => $grandTotal,
            ],
        ];
    }
}
