<?php

declare(strict_types=1);

namespace Modules\Resturants\Services\Promotions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Support\RestaurantPromotionCapability;
use Modules\User\Services\RestaurantFoodEligibility;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RestaurantCartPricingService
{
    private const RELATIONS = [
        'restaurant', 'items.product.offers', 'items.product.modifierGroups.modifiers',
        'items.product.substitutions', 'items.substituteProduct', 'items.modifiers',
    ];

    public function __construct(
        private readonly RestaurantPromotionEngine $engine,
        private readonly RestaurantPromotionRuleResolver $rules,
    ) {}

    /** Read-only; callers doing placement must already hold the cart lock. */
    public function price(Cart $cart, bool $lockRules = false): PromotionPricingResult
    {
        $at = CarbonImmutable::now();
        $restaurantId = (int) $cart->restaurant_id;
        if ($lockRules) {
            // Stable lock order: quote/receipt, cart, offers, cart items, products, modifiers.
            // Offer edits lock the rule before modifying its pivots.
            DB::table('offers')->where('restaurant_id', $restaurantId)->orderBy('id')->lockForUpdate()->get(['id']);
            $items = $cart->items()->orderBy('id')->lockForUpdate()->get();
            $productIds = $items->pluck('product_id')->merge($items->pluck('substitute_product_id'))->filter()->unique()->all();
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $modifierIds = DB::table('cart_item_modifier')->whereIn('cart_item_id', $items->pluck('id'))->pluck('modifier_id')->unique()->all();
            Modifier::query()->whereIn('id', $modifierIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $cart->load(self::RELATIONS);
        } else {
            $cart->loadMissing(self::RELATIONS);
        }

        $lines = [];
        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product || (int) $product->restaurant_id !== $restaurantId) {
                throw ValidationException::withMessages(['cart' => 'Cart items must belong to the same restaurant.']);
            }
            $modifiers = $item->modifiers->sortBy('id');
            $modifierIds = $modifiers->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $modifierPrices = $modifiers->map(fn (Modifier $modifier): array => [(int) $modifier->id, self::minor($modifier->price)])->values()->all();
            $regular = self::minor($product->price);
            $baseline = min($regular, self::minor($product->effectivePrice($at)));
            $signature = hash('sha256', json_encode([
                $item->signature_hash, $modifierPrices, $item->substitute_product_id, $item->special_instructions,
            ], JSON_THROW_ON_ERROR));
            $available = (bool) $cart->restaurant?->is_active
                && ($item->substitute_product_id === null || $item->substituteProduct !== null)
                && $this->configurationIsEligible($product, $modifierIds, $item->substituteProduct);
            $lines[] = new PromotionCartLine(
                id: (int) $item->id,
                restaurantId: $restaurantId,
                productId: (int) $product->id,
                quantity: (int) $item->quantity,
                regularBase: $regular,
                baselineBase: $baseline,
                modifierAmount: array_sum(array_column($modifierPrices, 1)),
                rewardOfferId: $item->reward_offer_id === null ? null : (int) $item->reward_offer_id,
                available: $available,
                signature: $signature,
            );
        }

        $rules = $this->rules->resolve($restaurantId, array_values(array_unique(array_map(fn (PromotionCartLine $line): int => $line->productId, $lines))), $at);
        $result = $this->engine->price($restaurantId, $lines, $rules, $at);
        if ($result->boundedFallback) {
            Log::warning('restaurant_promotion_bounded_fallback', ['cart_id' => $cart->id, 'restaurant_id' => $restaurantId, 'lines' => count($lines), 'rules' => count($rules)]);
        }

        return $result;
    }

    public static function minor(mixed $price): int
    {
        return max(0, (int) round((float) ($price ?? 0) * 100, 0, PHP_ROUND_HALF_UP));
    }

    public function configurationIsEligible(Product $product, array $modifierIds, ?Product $substitute = null): bool
    {
        if (! $product->isAvailableNow() || ! RestaurantFoodEligibility::isIndependentlyOrderable($product)) {
            return false;
        }
        $product->loadMissing(['modifierGroups.modifiers', 'substitutions']);
        $allowed = [];
        foreach ($product->modifierGroups as $group) {
            $active = (bool) ($group->getAttributes()['is_active'] ?? true);
            $selected = 0;
            foreach ($group->modifiers as $modifier) {
                $id = (int) $modifier->id;
                if ($active && (bool) ($modifier->getAttributes()['is_available'] ?? true)) {
                    $allowed[] = $id;
                }
                if (in_array($id, $modifierIds, true)) {
                    $selected++;
                }
            }
            if ($active && ($selected < max((int) $group->min_selections, $group->is_required ? 1 : 0)
                || $selected > (int) $group->max_selections)) {
                return false;
            }
        }
        if (array_diff($modifierIds, $allowed) !== []) {
            return false;
        }
        if ($substitute !== null && ((int) $substitute->restaurant_id !== (int) $product->restaurant_id
            || ! $substitute->isAvailableNow()
            || ! $product->substitutions->contains(fn ($entry): bool => (int) $entry->substitute_product_id === (int) $substitute->id))) {
            return false;
        }

        return true;
    }

    public function requiresConfirmation(Cart $cart, PromotionPricingResult $pricing): bool
    {
        return (bool) $cart->promotion_pricing_required || $pricing->hasAdvancedPricing();
    }

    public function assertCompatible(Cart $cart, PromotionPricingResult $pricing): void
    {
        if ($this->requiresConfirmation($cart, $pricing) && ! RestaurantPromotionCapability::clientSupports()) {
            throw new ConflictHttpException('PROMOTION_CLIENT_UPGRADE_REQUIRED');
        }
    }

    /** Server-owned sticky marker. It prevents silent full-price placement after a rule expires or is disabled. */
    public function rememberAdvancedPricing(Cart $cart, PromotionPricingResult $pricing): void
    {
        if ($pricing->hasAdvancedPricing() && ! $cart->promotion_pricing_required) {
            DB::table('carts')->where('id', $cart->id)->update(['promotion_pricing_required' => true]);
            $cart->promotion_pricing_required = true;
        }
    }

    public function assertPreviewable(Cart $cart, PromotionPricingResult $pricing): void
    {
        $this->assertCompatible($cart, $pricing);
        if ($pricing->requiresReview()) {
            throw ValidationException::withMessages(['cart' => 'PROMOTION_REWARD_UNAVAILABLE']);
        }
        if ($this->requiresConfirmation($cart, $pricing)) {
            if (! $cart->restaurant?->is_active) {
                throw ValidationException::withMessages(['cart' => 'PROMOTION_RESTAURANT_UNAVAILABLE']);
            }
            foreach ($cart->items as $item) {
                if (($item->substitute_product_id !== null && $item->substituteProduct === null) || ! $this->configurationIsEligible($item->product,
                    $item->modifiers->pluck('id')->map(fn ($id): int => (int) $id)->all(), $item->substituteProduct)) {
                    throw ValidationException::withMessages(['cart' => 'PROMOTION_PRODUCT_UNAVAILABLE']);
                }
            }
        }
    }

    public function assertPlaceable(Cart $cart, PromotionPricingResult $pricing, bool $confirmed): void
    {
        $this->assertPreviewable($cart, $pricing);
        if ($this->requiresConfirmation($cart, $pricing) && ! $confirmed) {
            throw new ConflictHttpException('PROMOTION_CONFIRMATION_REQUIRED');
        }
    }
}