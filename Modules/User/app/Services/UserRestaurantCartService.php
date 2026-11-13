<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\OfferRewardType;
use Modules\Resturants\Enums\OfferType;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Services\Promotions\PromotionPricingResult;
use Modules\Resturants\Services\Promotions\RestaurantCartPricingService;
use Modules\Resturants\Support\RestaurantPromotionCapability;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class UserRestaurantCartService
{
    public function __construct(private readonly RestaurantCartPricingService $pricing) {}

    private const CART_RELATIONS = [
        'restaurant.media',
        'items.product.category',
        'items.product.media',
        'items.product.offers',
        'items.product.modifierGroups.modifiers',
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

    /**
     * Add one complete offer application, using the same pricing and modifier
     * validation as ordinary cart writes. A failed claim rolls back both lines.
     */
    public function addOffer(
        int $userId,
        int $offerId,
        int $productId,
        int $quantity,
        int $ruleVersion,
        array $modifierIds = [],
        ?string $note = null,
        ?int $rewardProductId = null,
        array $rewardModifierIds = [],
        ?string $rewardNote = null,
    ): array {
        return DB::transaction(function () use ($userId, $offerId, $productId, $quantity, $ruleVersion, $modifierIds, $note, $rewardProductId, $rewardModifierIds, $rewardNote): array {
            $offer = RestaurantPromotionCatalog::constrain(Offer::query())
                ->whereKey($offerId)
                ->whereHas('restaurant', fn ($q) => $q->where('is_active', true))
                ->with(['products', 'rewardProducts'])
                ->firstOrFail();

            if ((int) ($offer->rule_version ?? 1) !== $ruleVersion) {
                throw new ConflictHttpException('PROMOTION_RULE_CHANGED');
            }
            $product = $offer->products->firstWhere('id', $productId);
            if (! $product || (int) $product->restaurant_id !== (int) $offer->restaurant_id
                || ! $product->isAvailableNow() || ! $product->isIndependentlyOrderable()) {
                throw ValidationException::withMessages(['productId' => 'PROMOTION_PRODUCT_UNAVAILABLE']);
            }

            $advanced = ! $offer->isProductDiscount();
            $specific = $advanced && $offer->reward_type === OfferRewardType::SpecificProducts;
            $requiredQuantity = $advanced
                ? (int) $offer->buy_quantity + ($specific ? 0 : (int) $offer->reward_quantity)
                : null;
            if ($quantity < 1 || $quantity > 50 || ($advanced && $quantity !== $requiredQuantity)) {
                throw ValidationException::withMessages(['quantity' => 'PROMOTION_REQUIREMENTS_NOT_MET']);
            }
            if ($specific !== ($rewardProductId !== null)) {
                throw ValidationException::withMessages(['rewardProductId' => 'PROMOTION_REWARD_UNAVAILABLE']);
            }
            if ($specific) {
                $reward = $offer->rewardProducts->firstWhere('id', $rewardProductId);
                if (! $reward || (int) $reward->restaurant_id !== (int) $offer->restaurant_id
                    || ! $reward->isAvailableNow() || ! $reward->isIndependentlyOrderable()
                    || (int) $offer->reward_quantity > 50) {
                    throw ValidationException::withMessages(['rewardProductId' => 'PROMOTION_REWARD_UNAVAILABLE']);
                }
            }

            $cart = $this->normalizeCart($this->resolveActiveCart($userId, (int) $offer->restaurant_id));
            $previous = $advanced
                ? (int) (collect($this->pricing->price($cart, true)->promotionsPayload())->firstWhere('offerId', $offerId)['applications'] ?? 0)
                : 0;
            $added = $this->addItem($userId, $productId, $quantity, $modifierIds, note: $note);
            $payload = $added['cart'];

            if ($specific) {
                $signature = $this->signatureHash($rewardProductId, $rewardModifierIds, null, $rewardNote, $offerId);
                $existing = CartItem::query()->where('cart_id', $cart->id)
                    ->where('signature_hash', $signature)->lockForUpdate()->first();
                $payload = $this->claimReward(
                    userId: $userId,
                    cartId: (int) $cart->id,
                    offerId: $offerId,
                    productId: $rewardProductId,
                    quantity: (int) ($existing?->quantity ?? 0) + (int) $offer->reward_quantity,
                    modifierIds: $rewardModifierIds,
                    substituteProductId: null,
                    note: $rewardNote,
                    expectedRevision: (int) $payload['revision'],
                );
            }

            // Never silently add full-price units if the selected promotion
            // lost to another rule, was capped, or no longer qualifies.
            if ($advanced) {
                $application = collect($payload['appliedPromotions'] ?? [])->firstWhere('offerId', $offerId);
                if ((int) ($application['applications'] ?? 0) <= $previous) {
                    throw ValidationException::withMessages(['promotion' => 'PROMOTION_REQUIREMENTS_NOT_MET']);
                }
            }

            return $payload;
        });
    }

    public function addItem(
        int $userId,
        int $productId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $note = null,
        string $quantityMode = 'increment',
        ?int $expectedRevision = null,
    ): array {
        return DB::transaction(function () use ($userId, $productId, $quantity, $modifierIds, $substituteProductId, $note, $quantityMode, $expectedRevision): array {
            $product = Product::query()
                ->with(['offers', 'modifierGroups.modifiers'])
                ->findOrFail($productId);

            if (! RestaurantFoodEligibility::isIndependentlyOrderable($product)) {
                throw ValidationException::withMessages([
                    'productId' => ['This item is an add-on and cannot be ordered independently.'],
                ]);
            }

            if (! $product->restaurant_id) {
                throw ValidationException::withMessages([
                    'productId' => ['The selected product is not linked to a restaurant.'],
                ]);
            }

            $cart = $this->resolveActiveCart($userId, (int) $product->restaurant_id);
            $cart = $this->normalizeCart($cart);
            $this->assertExpectedRevision($cart, $expectedRevision);
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
        ?int $expectedRevision = null,
        bool $convertRewardToOrdinary = false,
    ): array {
        return DB::transaction(function () use ($userId, $cartId, $itemId, $quantity, $modifierIds, $substituteProductId, $note, $replaceSubstituteProduct, $replaceNote, $expectedRevision, $convertRewardToOrdinary): array {
            $lockedCart = Cart::query()->whereKey($cartId)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $this->assertExpectedRevision($lockedCart, $expectedRevision);
            $item = CartItem::query()
                ->whereKey($itemId)
                ->where('cart_id', $cartId)
                ->whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->with(['product.offers', 'product.modifierGroups.modifiers', 'cart', 'modifiers'])
                ->lockForUpdate()
                ->firstOrFail();

            $product = $item->product;
            $originalQuantity = (int) $item->quantity;
            $originalRewardOfferId = $item->reward_offer_id;
            if ($originalRewardOfferId !== null || $convertRewardToOrdinary) {
                abort_unless(RestaurantPromotionCapability::clientSupports(), 409, 'PROMOTION_CLIENT_UPGRADE_REQUIRED');
            }
            if ($convertRewardToOrdinary && $originalRewardOfferId === null) {
                throw ValidationException::withMessages(['convertRewardToOrdinary' => 'This item is not a claimed reward.']);
            }
            $nextRewardOfferId = $convertRewardToOrdinary ? null : $originalRewardOfferId;

            $this->assertProductBelongsToCartRestaurant($item->cart, $product);
            if (! RestaurantFoodEligibility::isIndependentlyOrderable($product)) {
                throw ValidationException::withMessages([
                    'productId' => ['This item is an add-on and cannot be ordered independently.'],
                ]);
            }

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
                rewardOfferId: $nextRewardOfferId,
            );

            if ($nextRewardOfferId !== null) {
                $substitute = $nextSubstituteProductId === null ? null : Product::query()->findOrFail($nextSubstituteProductId);
                if (! $this->pricing->configurationIsEligible($product, $normalizedModifierIds, $substitute)) {
                    throw ValidationException::withMessages(['productId' => 'PROMOTION_REWARD_UNAVAILABLE']);
                }
            }

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
                    'reward_offer_id' => $nextRewardOfferId,
                ]);
                $targetItem->save();
                $this->syncModifiers($targetItem, $modifiers);
                $item->delete();

                $payload = $this->toPayload($this->normalizeCart($targetItem->cart));
                if ($nextRewardOfferId !== null) {
                    $this->assertRewardAllocated($payload, (int) $targetItem->id);
                }

                return $payload;
            }

            $item->fill([
                'quantity' => $quantity,
                'substitute_product_id' => $nextSubstituteProductId,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $nextNote,
                'signature_hash' => $signatureHash,
                'reward_offer_id' => $nextRewardOfferId,
            ]);
            $item->save();

            if ($modifierIds !== null) {
                $this->syncModifiers($item, $modifiers);
            }

            $payload = $this->toPayload($this->normalizeCart($item->cart));
            if ($nextRewardOfferId !== null && $quantity >= $originalQuantity) {
                $this->assertRewardAllocated($payload, (int) $item->id);
            }

            return $payload;
        });
    }

    /**
     * Explicit desired-quantity claim. Repeating the same request cannot add
     * another reward; optional conversion moves units from a normal line.
     */
    public function claimReward(
        int $userId,
        int $cartId,
        int $offerId,
        int $productId,
        int $quantity,
        array $modifierIds,
        ?int $substituteProductId,
        ?string $note,
        int $expectedRevision,
        ?int $convertItemId = null,
    ): array {
        abort_unless(RestaurantPromotionCapability::supported(), 404);
        if ($quantity < 1 || $quantity > 50) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be between 1 and 50.']);
        }

        return DB::transaction(function () use ($userId, $cartId, $offerId, $productId, $quantity, $modifierIds, $substituteProductId, $note, $expectedRevision, $convertItemId): array {
            $cart = Cart::query()->whereKey($cartId)->where('user_id', $userId)
                ->whereNotNull('restaurant_id')->lockForUpdate()->firstOrFail();
            $cart = $this->normalizeCart($cart);
            $offer = Offer::query()->whereKey($offerId)->where('restaurant_id', $cart->restaurant_id)
                ->where('offer_type', OfferType::BuyXGetY->value)->activeAt()
                ->with('rewardProducts')->firstOrFail();
            if ($offer->reward_type !== OfferRewardType::SpecificProducts || ! $offer->rewardProducts->contains('id', $productId)) {
                throw ValidationException::withMessages(['productId' => 'PROMOTION_REWARD_UNAVAILABLE']);
            }
            $product = Product::query()->whereKey($productId)->where('restaurant_id', $cart->restaurant_id)
                ->with(['offers', 'modifierGroups.modifiers', 'substitutions'])->firstOrFail();
            $modifierIds = $this->normalizeModifierIds($modifierIds);
            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $substitute = $substituteProductId === null ? null : Product::query()->findOrFail($substituteProductId);
            if (! $this->pricing->configurationIsEligible($product, $modifierIds, $substitute)) {
                throw ValidationException::withMessages(['productId' => 'PROMOTION_REWARD_UNAVAILABLE']);
            }
            $note = $this->normalizeNote($note);
            $signature = $this->signatureHash($productId, $modifierIds, $substituteProductId, $note, $offerId);
            $rewardItem = CartItem::query()->where('cart_id', $cartId)->where('signature_hash', $signature)
                ->lockForUpdate()->first();

            // Retry is a read of the same desired state, even after its revision changed.
            if ($rewardItem && (int) $rewardItem->quantity === $quantity) {
                $fresh = $cart->fresh(self::CART_RELATIONS);
                $payload = $this->toPayload($fresh, $this->pricing->price($fresh, true));
                $this->assertRewardAllocated($payload, (int) $rewardItem->id);

                return $payload;
            }

            $this->assertExpectedRevision($cart, $expectedRevision);
            $delta = $quantity - (int) ($rewardItem?->quantity ?? 0);
            if ($convertItemId !== null) {
                if ($delta <= 0) {
                    throw ValidationException::withMessages(['convertItemId' => 'Conversion must add a claimed reward.']);
                }
                $normal = CartItem::query()->whereKey($convertItemId)->where('cart_id', $cartId)
                    ->whereNull('reward_offer_id')->lockForUpdate()->firstOrFail();
                $normalSignature = $this->signatureHash($productId, $modifierIds, $substituteProductId, $note);
                if ($normal->signature_hash !== $normalSignature || (int) $normal->quantity < $delta) {
                    throw ValidationException::withMessages(['convertItemId' => 'Choose the same configuration and an available quantity.']);
                }
                if ((int) $normal->quantity === $delta) {
                    $normal->delete();
                } else {
                    $normal->quantity -= $delta;
                    $normal->total_price = (float) $normal->unit_price * (int) $normal->quantity;
                    $normal->save();
                }
            }

            $rewardItem ??= new CartItem([
                'cart_id' => $cartId, 'product_id' => $productId, 'reward_offer_id' => $offerId,
                'signature_hash' => $signature,
            ]);
            $baseline = $this->unitPrice($product, $modifiers);
            $rewardItem->fill([
                'quantity' => $quantity, 'unit_price' => $baseline, 'total_price' => $baseline * $quantity,
                'substitute_product_id' => $substituteProductId, 'special_instructions' => $note,
            ])->save();
            $this->syncModifiers($rewardItem, $modifiers);

            $fresh = $this->normalizeCart($cart);
            $payload = $this->toPayload($fresh, $this->pricing->price($fresh, true));
            $this->assertRewardAllocated($payload, (int) $rewardItem->id);

            return $payload;
        });
    }

    private function assertRewardAllocated(array $payload, int $itemId): void
    {
        $line = collect($payload['items'])->firstWhere('id', $itemId);
        if ($line === null || ($line['invalidRewardQuantity'] ?? 0) > 0
            || ($line['rewardQuantity'] ?? 0) !== (int) $line['quantity']) {
            throw ValidationException::withMessages(['promotion' => 'PROMOTION_REQUIREMENTS_NOT_MET']);
        }
    }

    public function deleteItem(int $userId, int $cartId, int $itemId, ?int $expectedRevision = null): array
    {
        return DB::transaction(function () use ($userId, $cartId, $itemId, $expectedRevision): array {
            $lockedCart = Cart::query()->whereKey($cartId)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $this->assertExpectedRevision($lockedCart, $expectedRevision);
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

    private function assertExpectedRevision(Cart $cart, ?int $expectedRevision): void
    {
        if ($expectedRevision !== null && ! array_key_exists('revision', $cart->getAttributes())) {
            throw new ConflictHttpException('CART_REVISION_UNAVAILABLE');
        }
        if ($expectedRevision !== null && (int) $cart->revision !== $expectedRevision) {
            throw new ConflictHttpException('CART_CONFLICT');
        }
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
                ->with(['product.media', 'product.offers', 'product.modifierGroups.modifiers', 'modifiers'])
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
                    rewardOfferId: $item->reward_offer_id,
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
                $unitPrice = $this->unitPrice($keeper->product, $keeper->modifiers);
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

        $normalized = preg_replace('/\s+/u', ' ', mb_trim($note));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<int>  $modifierIds
     */
    private function signatureHash(int $productId, array $modifierIds, ?int $substituteProductId, ?string $note, ?int $rewardOfferId = null): string
    {
        return hash('sha256', json_encode([
            'product_id' => $productId,
            'modifier_ids' => $this->normalizeModifierIds($modifierIds),
            'substitute_product_id' => $substituteProductId,
            'note' => $this->normalizeNote($note),
            ...($rewardOfferId === null ? [] : ['reward_offer_id' => $rewardOfferId]),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Collection<int, Modifier>  $modifiers
     */
    private function unitPrice(Product $product, Collection $modifiers): float
    {
        $modifierTotal = (float) $modifiers->sum(fn (Modifier $modifier): float => (float) ($modifier->price ?? 0));

        return $product->effectivePrice() + $modifierTotal;
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
            'revision' => null,
            'merchant' => null,
            'items' => [],
            'productsCount' => 0,
            'amounts' => [
                'subtotal' => 0.0,
                'total' => 0.0,
                ...(RestaurantPromotionCapability::clientSupports() ? ['originalSubtotal' => 0.0, 'ordinaryDiscount' => 0.0, 'promotionDiscount' => 0.0, 'productDiscount' => 0.0] : []),
            ],
            ...(RestaurantPromotionCapability::clientSupports() ? ['pricingFingerprint' => null, 'appliedPromotions' => [], 'promotionProgress' => [], 'invalidRewards' => []] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(Cart $cart, ?PromotionPricingResult $priceResult = null): array
    {
        $priceResult ??= $this->pricing->price($cart);
        $this->pricing->assertCompatible($cart, $priceResult);
        $compatible = RestaurantPromotionCapability::clientSupports();
        if ($compatible) {
            $this->pricing->rememberAdvancedPricing($cart, $priceResult);
        }
        $mappedItems = $cart->items->loadMissing(['product.media', 'product.offers', 'product.modifierGroups.modifiers', 'modifiers'])->map(function (CartItem $item) use ($priceResult, $compatible): array {
            $selectedModifiers = $item->modifiers
                ->values()
                ->map(fn (Modifier $modifier): array => [
                    'id' => $modifier->id,
                    'modifierGroupId' => $modifier->modifier_group_id,
                    'name' => $modifier->name,
                    'price' => (float) ($modifier->price ?? 0),
                    'sortOrder' => (int) ($modifier->sort_order ?? 0),
                    // Legacy modifier schema has no is_available field.
                    'isAvailable' => (bool) ($modifier->getAttributes()['is_available'] ?? true),
                ])
                ->all();

            $availableAdditions = $this->productOptionsPayload($item->product);
            $primaryImageUrl = $item->product !== null
                ? ($item->product->getFirstMediaUrl('primary-image') ?: null)
                : null;
            $imageUrls = $item->product !== null
                ? $item->product->getMedia('images')->map(fn ($media) => $media->getUrl())->values()->all()
                : [];
            $modifierTotal = (float) collect($selectedModifiers)->sum('price');
            $originalProductPrice = $item->product !== null ? (float) ($item->product->price ?? 0) : 0.0;
            $effectiveProductPrice = $item->product !== null ? $item->product->effectivePrice() : 0.0;
            $hasDiscount = $item->product !== null && $effectiveProductPrice < $originalProductPrice;
            $originalUnitPrice = $originalProductPrice + $modifierTotal;

            return [
                'id' => $item->id,
                'productId' => $item->product_id,
                'name' => $item->product?->name,
                'primaryImageUrl' => $primaryImageUrl,
                'imageUrl' => $primaryImageUrl,
                'images' => $imageUrls,
                'imageUrls' => $imageUrls,
                'quantity' => (int) $item->quantity,
                'unitPrice' => (float) ($item->unit_price ?? 0),
                'originalUnitPrice' => $hasDiscount ? round($originalUnitPrice, 2) : null,
                'productPrice' => $effectiveProductPrice,
                'originalProductPrice' => $hasDiscount ? $originalProductPrice : null,
                'hasDiscount' => $hasDiscount,
                'totalPrice' => PromotionPricingResult::money($priceResult->lines[$item->id]['netTotal']),
                'originalTotalPrice' => $hasDiscount ? round($originalUnitPrice * (int) $item->quantity, 2) : null,
                'modifierIds' => $item->modifiers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'modifiers' => $selectedModifiers,
                'additions' => $selectedModifiers,
                'selectedAdditions' => $selectedModifiers,
                'availableAdditions' => $availableAdditions,
                'options' => $availableAdditions,
                'modifierGroups' => $availableAdditions,
                'substituteProductId' => $item->substitute_product_id,
                'note' => $item->special_instructions,
                ...($compatible ? [
                    ...$priceResult->linePayload((int) $item->id),
                    'rewardOfferId' => $item->reward_offer_id,
                    'hasPromotion' => $priceResult->lines[$item->id]['promotionDiscount'] > 0 || $priceResult->lines[$item->id]['rewardQuantity'] > 0,
                ] : []),
            ];
        })->values();

        $subtotal = (float) $mappedItems->sum('totalPrice');
        $restaurant = $cart->restaurant;

        return [
            'id' => $cart->id,
            'revision' => array_key_exists('revision', $cart->getAttributes()) ? (int) $cart->revision : null,
            'merchant' => [
                'id' => $restaurant?->id,
                'name' => $restaurant?->name,
                'address' => $restaurant?->address,
                'city' => $restaurant?->city,
                'district' => $restaurant?->district,
                'locationDetails' => $restaurant?->location_details,
                'latitude' => $restaurant?->latitude !== null
                    ? (float) $restaurant->latitude
                    : null,
                'longitude' => $restaurant?->longitude !== null
                    ? (float) $restaurant->longitude
                    : null,
                'primaryImageUrl' => $restaurant !== null
                    ? \App\Support\ImageVariants::url($restaurant, 'primary-image', true)
                    : null,
                'bannerImageUrl' => $restaurant !== null
                    ? ($restaurant->getFirstMediaUrl('banner-image') ?: null)
                    : null,
            ],
            'items' => $mappedItems->all(),
            'productsCount' => (int) $mappedItems->sum('quantity'),
            'amounts' => $compatible ? $priceResult->amounts() : [
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
            ],
            ...($compatible ? [
                'pricingFingerprint' => $priceResult->fingerprint,
                'appliedPromotions' => $priceResult->promotionsPayload(),
                'promotionProgress' => $priceResult->progress,
                'invalidRewards' => $priceResult->invalidRewards,
                'requiresPriceConfirmation' => $this->pricing->requiresConfirmation($cart, $priceResult),
            ] : []),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productOptionsPayload(?Product $product): array
    {
        if ($product === null || ! $product->relationLoaded('modifierGroups')) {
            return [];
        }

        return $product->modifierGroups
            ->sortBy(fn ($group): int => (int) ($group->getAttributes()['sort_order'] ?? $group->id))
            ->values()
            ->map(fn ($group): array => [
                'id' => $group->id,
                'restaurantId' => $group->restaurant_id,
                'name' => $group->name,
                'isRequired' => (bool) $group->is_required,
                'minSelections' => (int) $group->min_selections,
                'maxSelections' => (int) $group->max_selections,
                'sortOrder' => (int) ($group->getAttributes()['sort_order'] ?? 0),
                'isActive' => (bool) ($group->getAttributes()['is_active'] ?? true),
                'modifiers' => $group->modifiers
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn (Modifier $modifier): array => [
                        'id' => $modifier->id,
                        'modifierGroupId' => $modifier->modifier_group_id,
                        'name' => $modifier->name,
                        'price' => (float) $modifier->price,
                        'sortOrder' => (int) $modifier->sort_order,
                        'isAvailable' => (bool) ($modifier->getAttributes()['is_available'] ?? true),
                    ])
                    ->all(),
            ])
            ->all();
    }
}