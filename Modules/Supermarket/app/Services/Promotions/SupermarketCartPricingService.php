<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services\Promotions;

use App\Services\Promotions\PromotionCartLine;
use App\Services\Promotions\PromotionEngine;
use App\Services\Promotions\PromotionPricingResult;
use App\Services\Promotions\PromotionRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\OfferRewardType;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Support\SupermarketPromotionCapability as Capability;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SupermarketCartPricingService
{
    public function price(SmCart $cart, bool $lockRules = false): PromotionPricingResult
    {
        $storeId = (int) $cart->store_id;
        if ($lockRules) {
            DB::table('sm_offers')->where('store_id', $storeId)->orderBy('id')->lockForUpdate()->get();
            $cart->items()->orderBy('id')->lockForUpdate()->get();
            SmProduct::whereIn('id', $cart->items()->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
        }
        $cart->load(['store', 'items.product.offerProducts.offer', 'items.product.modifierGroups.modifiers']);
        if ($lockRules) {
            $modifierIds = $cart->items->flatMap(fn ($i) => $i->modifier_ids ?? [])->unique()->all();
            \Modules\Supermarket\Models\SmModifier::whereIn('id', $modifierIds)->orderBy('id')->lockForUpdate()->get();
            $cart->load('items.product.modifierGroups.modifiers');
        }
        $at = CarbonImmutable::now();
        $lines = [];
        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product || (int) $product->store_id !== $storeId) {
                throw ValidationException::withMessages(['cart' => 'منتجات السلة يجب أن تتبع للمتجر نفسه.']);
            }
            $modifierIds = array_map('intval', $item->modifier_ids ?? []);
            sort($modifierIds);
            $modifierPrices = [];
            $validConfiguration = true;
            $allowed = [];
            foreach ($product->modifierGroups as $group) {
                $selected = 0;
                foreach ($group->modifiers as $modifier) {
                    if ($group->is_active && $modifier->is_available) {
                        $allowed[] = (int) $modifier->id;
                    }
                    if (in_array((int) $modifier->id, $modifierIds, true)) {
                        $selected++;
                        $modifierPrices[] = [(int) $modifier->id, (int) round((float) $modifier->price * 100)];
                    }
                }
                if ($group->is_active && ($selected < max((int) $group->min_selections, $group->is_required ? 1 : 0) || $selected > (int) $group->max_selections)) {
                    $validConfiguration = false;
                }
            }
            $available = $this->available($product) && $validConfiguration && array_diff($modifierIds, $allowed) === [];
            if ($item->substitute_product_id !== null) {
                $substitute = SmProduct::find($item->substitute_product_id);
                $available = $available && $substitute && (int) $substitute->store_id === $storeId && $this->available($substitute);
            }
            $stockRequested = (int) $cart->items->where('product_id', $product->id)->sum('quantity');
            $available = $available && ($product->stock_quantity === null || $stockRequested <= (int) $product->stock_quantity);
            $lines[] = new PromotionCartLine((int) $item->id, $storeId, (int) $product->id, (int) $item->quantity,
                (int) round((float) $product->price * 100), (int) round($product->effectivePrice() * 100),
                array_sum(array_column($modifierPrices, 1)), $item->reward_offer_id === null ? null : (int) $item->reward_offer_id,
                $available && (bool) $cart->store?->is_active && ! $cart->store?->is_temporarily_closed && ($cart->store?->suspension_until === null || $cart->store->suspension_until <= $at),
                hash('sha256', json_encode([$modifierIds, $modifierPrices, $item->note, $item->substitute_product_id, $product->stock_quantity], JSON_THROW_ON_ERROR)));
        }
        $rules = [];
        if (Capability::enabled()) {
            $offers = SmOffer::query()->where('store_id', $storeId)->where('promotion_type', 'buy_x_get_y')->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
                ->whereHas('offerProducts', fn ($q) => $q->whereIn('product_id', $cart->items->pluck('product_id')))
                ->with(['offerProducts.product', 'rewardProducts'])->orderBy('id')->get();
            foreach ($offers as $offer) {
                $type = OfferRewardType::tryFrom((string) $offer->reward_type);
                if ($type === null) {
                    continue;
                }
                $qualifiers = $offer->offerProducts->pluck('product');
                if ($qualifiers->contains(fn ($p) => ! $p || (int) $p->store_id !== $storeId) || $offer->rewardProducts->contains(fn ($p) => (int) $p->store_id !== $storeId)) {
                    continue;
                }
                $rules[] = new PromotionRule((int) $offer->id, $storeId, (int) $offer->rule_version, $offer->name,
                    (int) $offer->buy_quantity, (int) $offer->reward_quantity, $type, (int) round((float) $offer->reward_discount_percent * 100),
                    $qualifiers->pluck('id')->map(fn ($id) => (int) $id)->all(), $offer->rewardProducts->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    (bool) $offer->is_repeatable, $offer->max_applications_per_order, true, $offer->starts_at, $offer->ends_at,
                    $offer->rewardProducts->filter(fn ($p) => $this->available($p) && ($p->stock_quantity === null || (int) $p->stock_quantity >= (int) $offer->reward_quantity))->pluck('id')->map(fn ($id) => (int) $id)->values()->all(), 'supermarket');
            }
        }
        $result = app(PromotionEngine::class)->price($storeId, $lines, $rules, $at);
        if ($result->boundedFallback) {
            \Illuminate\Support\Facades\Log::warning('supermarket_promotion_bounded_fallback', ['cart_id' => $cart->id]);
        }

        return $result;
    }

    public function available(SmProduct $product): bool
    {
        return (bool) $product->is_available && ($product->expires_at === null || $product->expires_at->gt(now()))
            && ($product->stock_quantity === null || (int) $product->stock_quantity > 0);
    }

    public function rememberAdvancedPricing(SmCart $cart, PromotionPricingResult $pricing): void
    {
        if ($pricing->hasAdvancedPricing() && ! $cart->promotion_pricing_required) {
            DB::table('sm_carts')->where('id', $cart->id)->update(['promotion_pricing_required' => true]);
            $cart->promotion_pricing_required = true;
        }
    }

    public function assertPreviewable(SmCart $cart, PromotionPricingResult $pricing): void
    {
        if (($cart->promotion_pricing_required || $pricing->hasAdvancedPricing()) && ! Capability::clientSupports()) {
            throw new ConflictHttpException('PROMOTION_CLIENT_UPGRADE_REQUIRED');
        }
        if ($pricing->requiresReview()) {
            throw ValidationException::withMessages(['cart' => 'PROMOTION_REWARD_UNAVAILABLE']);
        }
        if ($cart->promotion_pricing_required || $pricing->hasAdvancedPricing()) {
            if (! $cart->store?->is_active || $cart->store->is_temporarily_closed
                || ($cart->store->suspension_until !== null && $cart->store->suspension_until->isFuture())) {
                throw ValidationException::withMessages(['cart' => 'PROMOTION_STORE_UNAVAILABLE']);
            }
            foreach ($cart->items as $item) {
                if (! $item->product || ! $this->available($item->product)) {
                    throw ValidationException::withMessages(['cart' => 'PROMOTION_PRODUCT_UNAVAILABLE']);
                }
            }
        }
    }
}
