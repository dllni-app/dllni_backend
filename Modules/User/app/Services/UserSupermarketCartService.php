<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\MasterProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmModifier;
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
    public function addItem(
        int $userId,
        int $productId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $note = null,
    ): array {
        return DB::transaction(function () use ($userId, $productId, $quantity, $modifierIds, $substituteProductId, $note): array {
            $product = SmProduct::query()->with(['modifierGroups.modifiers'])->findOrFail($productId);

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

            $normalizedModifierIds = array_values(array_unique(array_map('intval', $modifierIds)));
            sort($normalizedModifierIds);
            $normalizedNote = is_string($note) && trim($note) !== '' ? trim($note) : null;
            $modifierTotal = $this->validateAndPriceModifiers($product, $normalizedModifierIds);
            $this->validateSubstituteProduct($product, $substituteProductId);

            $cart = $this->normalizeCart($this->resolveStoreCart($userId, (int) $product->store_id));
            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0) + $modifierTotal;

            $productItems = SmCartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get();
            $currentProductQuantity = (int) $productItems->sum('quantity');
            if ($product->stock_quantity !== null &&
                $currentProductQuantity + $quantity > (int) $product->stock_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['الكمية المطلوبة تتجاوز المخزون المتاح حالياً.'],
                ]);
            }

            $item = $productItems->first(function (SmCartItem $candidate) use ($normalizedModifierIds, $substituteProductId, $normalizedNote): bool {
                $candidateModifierIds = array_values(array_map('intval', $candidate->modifier_ids ?? []));
                sort($candidateModifierIds);

                return $candidateModifierIds === $normalizedModifierIds
                    && (int) ($candidate->substitute_product_id ?? 0) === (int) ($substituteProductId ?? 0)
                    && ($candidate->note ?: null) === $normalizedNote;
            });

            if ($item) {
                $item->update([
                    'quantity' => (int) $item->quantity + $quantity,
                    'unit_price' => $unitPrice,
                    'modifier_ids' => $normalizedModifierIds,
                    'substitute_product_id' => $substituteProductId,
                    'note' => $normalizedNote,
                ]);
            } else {
                $item = SmCartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'modifier_ids' => $normalizedModifierIds,
                    'substitute_product_id' => $substituteProductId,
                    'note' => $normalizedNote,
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
                ->with(['product.modifierGroups.modifiers', 'cart.store'])
                ->lockForUpdate()
                ->firstOrFail();

            $product = $item->product;
            if (! $product || ! $product->is_available) {
                throw ValidationException::withMessages([
                    'productId' => ['The selected product is not available.'],
                ]);
            }

            $otherQuantity = (int) SmCartItem::query()
                ->where('cart_id', $cartId)
                ->where('product_id', $product->id)
                ->where('id', '!=', $itemId)
                ->sum('quantity');
            if ($product->stock_quantity !== null &&
                $otherQuantity + $quantity > (int) $product->stock_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['الكمية المطلوبة تتجاوز المخزون المتاح حالياً.'],
                ]);
            }

            $modifierIds = array_values(array_unique(array_map('intval', $item->modifier_ids ?? [])));
            sort($modifierIds);
            $modifierTotal = $this->validateAndPriceModifiers($product, $modifierIds);
            $this->validateSubstituteProduct($product, $item->substitute_product_id);
            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0) + $modifierTotal;
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

            foreach ($items->groupBy(fn (SmCartItem $item): string => $this->cartItemSignature($item)) as $group) {
                /** @var SmCartItem $keeper */
                $keeper = $group->first();
                $mergedQuantity = (int) $group->sum(fn (SmCartItem $item): int => max(1, (int) $item->quantity));
                $basePrice = (float) ($keeper->product?->discounted_price ?? $keeper->product?->price ?? $keeper->unit_price ?? 0);
                $unitPrice = $basePrice + $this->modifierTotalForItem($keeper);

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
        $signature = $this->cartItemSignature($item);
        $existing = SmCartItem::query()
            ->where('cart_id', $targetCart->id)
            ->where('product_id', $item->product_id)
            ->lockForUpdate()
            ->get()
            ->first(
                fn (SmCartItem $candidate): bool =>
                    $this->cartItemSignature($candidate) === $signature
            );

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

    private function cartItemSignature(SmCartItem $item): string
    {
        $modifierIds = array_values(array_unique(array_map('intval', $item->modifier_ids ?? [])));
        sort($modifierIds);

        return implode('|', [
            (string) $item->product_id,
            json_encode($modifierIds, JSON_THROW_ON_ERROR),
            (string) ($item->substitute_product_id ?? 0),
            trim((string) ($item->note ?? '')),
        ]);
    }

    private function modifierTotalForItem(SmCartItem $item): float
    {
        $modifierIds = array_values(array_unique(array_map('intval', $item->modifier_ids ?? [])));
        if ($modifierIds === [] || ! $item->product) {
            return 0.0;
        }

        return (float) $item->product->modifierGroups
            ->flatMap(fn ($group) => $group->modifiers)
            ->where('is_available', true)
            ->whereIn('id', $modifierIds)
            ->sum('price');
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
            'modifierIds' => $item->modifier_ids ?? [],
            'modifiers' => SmModifier::query()
                ->whereIn('id', $item->modifier_ids ?? [])
                ->get()
                ->map(fn (SmModifier $modifier): array => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'price' => (float) $modifier->price,
                ])->values()->all(),
            'substituteProductId' => $item->substitute_product_id,
            'note' => $item->note,
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
    private function validateAndPriceModifiers(SmProduct $product, array $modifierIds): float
    {
        $selected = collect($modifierIds);
        $price = 0.0;

        foreach ($product->modifierGroups as $group) {
            if (! $group->is_active) {
                continue;
            }
            $available = $group->modifiers->where('is_available', true);
            $selectedForGroup = $available->whereIn('id', $selected)->values();
            $count = $selectedForGroup->count();
            $min = max(0, (int) $group->min_selections);
            $max = max($min, (int) $group->max_selections);

            if ($group->is_required && $count < max(1, $min)) {
                throw ValidationException::withMessages([
                    'modifierIds' => ["يرجى اختيار الخيارات المطلوبة لمجموعة {$group->name}."],
                ]);
            }
            if ($count < $min || ($max > 0 && $count > $max)) {
                throw ValidationException::withMessages([
                    'modifierIds' => ["عدد الخيارات المحدد لمجموعة {$group->name} غير صالح."],
                ]);
            }
            $price += (float) $selectedForGroup->sum('price');
        }

        $allowedIds = $product->modifierGroups
            ->flatMap(fn ($group) => $group->modifiers->where('is_available', true)->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_diff($modifierIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'modifierIds' => ['أحد الخيارات المحددة غير متاح لهذا المنتج.'],
            ]);
        }

        return $price;
    }

    private function validateSubstituteProduct(SmProduct $product, ?int $substituteProductId): void
    {
        if ($substituteProductId === null) {
            return;
        }

        $substitute = SmProduct::query()->find($substituteProductId);
        if (! $substitute || ! $substitute->is_available || (int) $substitute->store_id !== (int) $product->store_id) {
            throw ValidationException::withMessages([
                'substituteProductId' => ['المنتج البديل المحدد غير متاح من نفس المتجر.'],
            ]);
        }
    }

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
