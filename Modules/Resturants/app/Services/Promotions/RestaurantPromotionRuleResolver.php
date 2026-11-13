<?php

declare(strict_types=1);

namespace Modules\Resturants\Services\Promotions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Modules\Resturants\Enums\OfferRewardType;
use Modules\Resturants\Enums\OfferType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Support\RestaurantPromotionCapability;
use Modules\User\Services\RestaurantFoodEligibility;
use ValueError;

final class RestaurantPromotionRuleResolver
{
    /** @return list<PromotionRule> */
    public function resolve(int $restaurantId, array $cartProductIds, CarbonInterface $at): array
    {
        if (! RestaurantPromotionCapability::enabled() || $cartProductIds === []) {
            return [];
        }
        $offers = Offer::query()
            ->where('restaurant_id', $restaurantId)
            ->where('offer_type', OfferType::BuyXGetY->value)
            ->activeAt($at)
            ->whereHas('products', fn ($q) => $q->whereIn('products.id', $cartProductIds))
            ->with(['products', 'rewardProducts'])
            ->orderBy('id')
            ->get();

        $rules = [];
        foreach ($offers as $offer) {
            $rule = $this->fromOffer($offer, $at);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public function fromOffer(Offer $offer, CarbonInterface $at): ?PromotionRule
    {
        try {
            $offer->loadMissing(['products', 'rewardProducts']);
            $restaurantId = (int) $offer->restaurant_id;
            if ($offer->offer_type !== OfferType::BuyXGetY || ! $offer->reward_type instanceof OfferRewardType) {
                return null;
            }
            $qualifying = $offer->products->filter(fn (Product $product): bool =>
                (int) $product->restaurant_id === $restaurantId && RestaurantFoodEligibility::isIndependentlyOrderable($product));
            $rewards = $offer->rewardProducts->filter(fn (Product $product): bool =>
                (int) $product->restaurant_id === $restaurantId && RestaurantFoodEligibility::isIndependentlyOrderable($product));
            if ($qualifying->count() !== $offer->products->count() || $rewards->count() !== $offer->rewardProducts->count()) {
                Log::warning('restaurant_promotion_invalid_product_scope', ['offer_id' => $offer->id, 'restaurant_id' => $restaurantId]);

                return null;
            }
            $rewardIds = $rewards->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $availableRewardIds = $rewards->filter(fn (Product $product): bool => $product->isAvailableNow())
                ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $rule = new PromotionRule(
                id: (int) $offer->id,
                restaurantId: $restaurantId,
                version: (int) $offer->rule_version,
                name: (string) $offer->name,
                buyQuantity: (int) $offer->buy_quantity,
                rewardQuantity: (int) $offer->reward_quantity,
                rewardType: $offer->reward_type,
                rewardPercentBps: (int) round((float) $offer->reward_discount_percent * 100),
                qualifyingIds: $qualifying->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                rewardIds: $rewardIds,
                repeatable: (bool) $offer->is_repeatable,
                maxApplications: $offer->max_applications_per_order,
                active: (bool) $offer->is_active,
                startsAt: $offer->starts_at,
                endsAt: $offer->ends_at,
                availableRewardIds: $availableRewardIds,
            );
            if (! $rule->isValidAt($restaurantId, $at)) {
                Log::warning('restaurant_promotion_invalid_rule', ['offer_id' => $offer->id, 'restaurant_id' => $restaurantId]);

                return null;
            }

            return $rule;
        } catch (ValueError $exception) {
            Log::warning('restaurant_promotion_invalid_enum', ['offer_id' => $offer->id, 'restaurant_id' => $offer->restaurant_id]);

            return null;
        }
    }
}
