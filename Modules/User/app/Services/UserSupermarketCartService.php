<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\MasterProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class UserSupermarketCartService
{
    private const CART_RELATIONS = [
        'store',
        'items.product.category',
        'items.product.store',
        'items.product.media',
        'items.product.masterProduct.media',
        'items.product.modifierGroups.modifiers',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return SmCart::query()
            ->where('user_id', $userId)
            ->with(self::CART_RELATIONS)
            ->latest()
            ->get()
            ->filter(fn (SmCart $cart): bool => $cart->items->isNotEmpty())
            ->map(fn (SmCart $cart): array => $this->toPayload($this->normalizeCart($cart)))
            ->filter(fn (array $payload): bool => $payload['id'] !== null && $payload['items'] !== [])
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
            ->with(self::CART_RELATIONS)
            ->firstOrFail();

        return $this->toPayload($this->normalizeCart($cart));
    }

    /**
     * @return array<string, mixed>
     */
    public function showForStore(int $userId, int $storeId): array
    {
        $store = SmStore::query()->findOrFail($storeId);

        $cart = SmCart::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->with(self::CART_RELATIONS)
            ->first();

        if ($cart === null || $cart->items->isEmpty()) {
            return $this->emptyCartPayload($store);
        }

        return $this->toPayload($this->normalizeCart($cart));
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteCart(int $userId, int $cartId): array
    {
        return DB::transaction(function () use ($userId, $cartId): array {
            $cart = SmCart::query()
                ->whereKey($cartId)
                ->where('user_id', $userId)
                ->with('store')
                ->lockForUpdate()
                ->firstOrFail();

            $store = $cart->store;
            $cart->delete();

            return $this->emptyCartPayload($store);
        });
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

            if (! $product->is_available) {
                throw ValidationException::withMessages([
                    'productId' => ['The selected product is not available.'],
                ]);
            }

            $cart = $this->normalizeCart($this->resolveStoreCart($userId, (int) $product->store_id));
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

            $cart = $this->normalizeCart($this->resolveStoreCart($userId, $storeId));

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
                ->with(['product', 'cart.store'])
                ->lockForUpdate()
                ->firstOrFail();

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
                ->with('cart.store')
                ->lockForUpdate()
                ->firstOrFail();

            $cart = $item->cart;
            $store = $cart?->store;
            $item->delete();

            $freshCart = $this->normalizeCart($cart);

            if ($freshCart->items->isEmpty()) {
                $freshCart->delete();

                return $this->emptyCartPayload($store);
            }

            return $this->toPayload($freshCart);
        });
    }

    private function resolveStoreCart(int $userId, int $storeId): SmCart
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
                ->with([
                    'product.category',
                    'product.store',
                    'product.media',
                    'product.masterProduct.media',
                    'product.modifierGroups.modifiers',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                return $lockedCart->fresh(self::CART_RELATIONS) ?? $lockedCart;
            }

            if ($lockedCart->store_id === null) {
                $firstStoreId = $items
                    ->map(fn (SmCartItem $item): ?int => $item->product?->store_id !== null ? (int) $item->product->store_id : null)
                    ->filter()
                    ->first();

                if ($firstStoreId !== null) {
                    $lockedCart->update(['store_id' => $firstStoreId]);
                    $lockedCart->store_id = $firstStoreId;
                }
            }

            foreach ($items as $item) {
                $itemStoreId = $item->product?->store_id !== null ? (int) $item->product->store_id : null;

                if ($itemStoreId === null) {
                    $item->delete();
                    continue;
                }

                if ((int) $lockedCart->store_id === $itemStoreId) {
                    continue;
                }

                $targetCart = $this->resolveStoreCart((int) $lockedCart->user_id, $itemStoreId);
                $this->moveOrMergeItem($item, $targetCart);
            }

            $items = SmCartItem::query()
                ->where('cart_id', $lockedCart->id)
                ->with([
                    'product.category',
                    'product.store',
                    'product.media',
                    'product.masterProduct.media',
                    'product.modifierGroups.modifiers',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

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

    private function moveOrMergeItem(SmCartItem $item, SmCart $targetCart): void
    {
        $existing = SmCartItem::query()
            ->where('cart_id', $targetCart->id)
            ->where('product_id', $item->product_id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $existing->update([
                'quantity' => (int) $existing->quantity + max(1, (int) $item->quantity),
                'unit_price' => (float) ($item->unit_price ?? $existing->unit_price ?? 0),
            ]);
            $item->delete();

            return;
        }

        $item->update(['cart_id' => $targetCart->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCartPayload(?SmStore $store = null): array
    {
        $merchant = $this->merchantPayload($store, $store?->id);

        return [
            'id' => null,
            'storeId' => $store?->id,
            'merchantId' => $store?->id,
            'merchant' => $merchant,
            'store' => $merchant,
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
        $items = $cart->items->loadMissing([
            'product.category',
            'product.store',
            'product.media',
            'product.masterProduct.media',
            'product.modifierGroups.modifiers',
        ]);

        $mappedItems = $items->map(fn (SmCartItem $item): array => $this->itemPayload($item))->values();
        $subtotal = (float) $mappedItems->sum('totalPrice');
        $store = $cart->relationLoaded('store') ? $cart->store : null;

        if ($store === null) {
            $store = $items->first()?->product?->store;
        }

        $storeId = $cart->store_id !== null
            ? (int) $cart->store_id
            : ($store?->id !== null ? (int) $store->id : null);
        $merchant = $this->merchantPayload($store, $storeId);

        return [
            'id' => $cart->id,
            'storeId' => $storeId,
            'merchantId' => $storeId,
            'merchant' => $merchant,
            'store' => $merchant,
            'items' => $mappedItems->all(),
            'productsCount' => (int) $mappedItems->sum('quantity'),
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(SmCartItem $item): array
    {
        $product = $item->product;
        $productImages = $this->productImages($product);
        $options = $this->productOptionsPayload($product);
        $merchant = $this->merchantPayload($product?->store, $product?->store_id !== null ? (int) $product->store_id : null);

        return [
            'id' => $item->id,
            'productId' => $item->product_id,
            'storeId' => $product?->store_id,
            'merchantId' => $product?->store_id,
            'name' => $product?->name,
            'primaryImageUrl' => $productImages['primaryImageUrl'],
            'imageUrl' => $productImages['primaryImageUrl'],
            'primaryImage' => $productImages['primaryImageUrl'],
            'images' => $productImages['imageUrls'],
            'imageUrls' => $productImages['imageUrls'],
            'quantity' => (int) $item->quantity,
            'unitPrice' => (float) ($item->unit_price ?? 0),
            'totalPrice' => round((float) ($item->unit_price ?? 0) * (int) $item->quantity, 2),
            'modifierIds' => [],
            'modifiers' => [],
            'additions' => $options,
            'options' => $options,
            'modifierGroups' => $options,
            'merchant' => $merchant,
            'store' => $merchant,
            'product' => $this->productPayload($product),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function merchantPayload(?SmStore $store, ?int $fallbackId = null): ?array
    {
        if ($store === null && $fallbackId === null) {
            return null;
        }

        return [
            'id' => $store?->id ?? $fallbackId,
            'name' => $store?->name,
            'slug' => $store?->slug,
            'description' => $store?->description,
            'address' => $store?->address,
            'city' => $store?->city,
            'neighborhood' => $store?->neighborhood,
            'latitude' => $store?->latitude !== null ? (float) $store->latitude : null,
            'longitude' => $store?->longitude !== null ? (float) $store->longitude : null,
            'phone' => $store?->phone,
            'email' => $store?->email,
            'logo' => $store?->logo,
            'cover' => $store?->cover,
            'primaryImageUrl' => $store?->logo,
            'logoImageUrl' => $store?->logo,
            'bannerImageUrl' => $store?->cover,
            'coverImageUrl' => $store?->cover,
            'averageRating' => $store?->average_rating !== null ? (float) $store->average_rating : null,
            'totalReviews' => $store?->total_reviews,
            'isActive' => $store?->is_active,
            'isFeatured' => $store?->is_featured,
            'isTemporarilyClosed' => $store?->is_temporarily_closed,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productPayload(?SmProduct $product): ?array
    {
        if ($product === null) {
            return null;
        }

        $productImages = $this->productImages($product);
        $options = $this->productOptionsPayload($product);
        $hasDiscount = $product->discounted_price !== null;
        $finalPrice = $product->discounted_price ?? $product->price;

        return [
            'id' => $product->id,
            'storeId' => $product->store_id,
            'merchantId' => $product->store_id,
            'categoryId' => $product->category_id,
            'category' => $product->relationLoaded('category') && $product->category !== null ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'masterProductId' => $product->master_product_id,
            'name' => $product->name,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'price' => $product->price !== null ? (float) $product->price : null,
            'discountedPrice' => $product->discounted_price !== null ? (float) $product->discounted_price : null,
            'finalPrice' => $finalPrice !== null ? (float) $finalPrice : null,
            'originalPrice' => $hasDiscount && $product->price !== null ? (float) $product->price : null,
            'hasDiscount' => $hasDiscount,
            'primaryImageUrl' => $productImages['primaryImageUrl'],
            'imageUrl' => $productImages['primaryImageUrl'],
            'primaryImage' => $productImages['primaryImageUrl'],
            'images' => $productImages['imageUrls'],
            'imageUrls' => $productImages['imageUrls'],
            'additions' => $options,
            'options' => $options,
            'modifierGroups' => $options,
            'stockQuantity' => $product->stock_quantity,
            'lowStockThreshold' => $product->low_stock_threshold,
            'expiresAt' => $product->expires_at?->toDateTimeString(),
            'isAvailable' => $product->is_available,
            'store' => $this->merchantPayload($product->store, $product->store_id !== null ? (int) $product->store_id : null),
        ];
    }

    /**
     * @return array{primaryImageUrl: string|null, imageUrls: array<int, string>}
     */
    private function productImages(?SmProduct $product): array
    {
        if ($product === null) {
            return [
                'primaryImageUrl' => null,
                'imageUrls' => [],
            ];
        }

        $primaryImageUrl = $product->getFirstMediaUrl(SmProduct::IMAGE_COLLECTION) ?: null;
        $imageUrls = $product->getMedia(SmProduct::IMAGE_COLLECTION)
            ->map(fn ($media): string => $media->getFullUrl())
            ->values()
            ->all();

        if ($primaryImageUrl === null && $product->relationLoaded('masterProduct') && $product->masterProduct !== null) {
            $masterMedia = $product->masterProduct->getFirstMedia(MasterProduct::IMAGE_COLLECTION)
                ?? $product->masterProduct->getFirstMedia();

            if ($masterMedia !== null) {
                $primaryImageUrl = $masterMedia->getFullUrl();
                $imageUrls = [$primaryImageUrl];
            }
        }

        return [
            'primaryImageUrl' => $primaryImageUrl,
            'imageUrls' => $imageUrls,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productOptionsPayload(?SmProduct $product): array
    {
        if ($product === null || ! $product->relationLoaded('modifierGroups')) {
            return [];
        }

        return $product->modifierGroups
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($group): array => [
                'id' => $group->id,
                'storeId' => $group->store_id,
                'name' => $group->name,
                'isRequired' => (bool) $group->is_required,
                'minSelections' => (int) $group->min_selections,
                'maxSelections' => (int) $group->max_selections,
                'sortOrder' => (int) $group->sort_order,
                'isActive' => (bool) $group->is_active,
                'modifiers' => $group->modifiers
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn ($modifier): array => [
                        'id' => $modifier->id,
                        'modifierGroupId' => $modifier->modifier_group_id,
                        'name' => $modifier->name,
                        'price' => (float) $modifier->price,
                        'sortOrder' => (int) $modifier->sort_order,
                        'isAvailable' => (bool) $modifier->is_available,
                    ])
                    ->all(),
            ])
            ->all();
    }
}
