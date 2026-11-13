<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Support\RestaurantPromotionCapability;

final class RestaurantPromotionCatalog
{
    public static function constrain(Builder|Relation $query, string $visibility = 'active'): Builder|Relation
    {
        $now = CarbonImmutable::now();
        if ($visibility === 'upcoming') {
            $query->where('offers.is_active', true)->where('offers.show_upcoming', true)
                ->where('offers.starts_at', '>', $now)
                ->where(fn (Builder $q) => $q->whereNull('offers.ends_at')->orWhereColumn('offers.ends_at', '>', 'offers.starts_at'));
        } elseif ($visibility === 'visible') {
            $query->where('offers.is_active', true)
                ->where(fn (Builder $q) => $q->whereNull('offers.ends_at')->orWhere('offers.ends_at', '>', $now))
                ->where(fn (Builder $q) => $q->whereNull('offers.starts_at')->orWhere('offers.starts_at', '<=', $now)
                    ->orWhere(fn (Builder $future) => $future->where('offers.show_upcoming', true)->where('offers.starts_at', '>', $now)
                        ->where(fn (Builder $end) => $end->whereNull('offers.ends_at')->orWhereColumn('offers.ends_at', '>', 'offers.starts_at'))));
        } else {
            $query->activeAt($now);
        }
        $query->whereHas('products', fn (Builder $p) => RestaurantFoodEligibility::browsableProducts($p)
            ->whereColumn('products.restaurant_id', 'offers.restaurant_id'));
        if (! RestaurantPromotionCapability::supported()) {
            return $query->where('offers.offer_type', 'product_discount');
        }

        return $query->where(function (Builder $q): void {
            $q->where('offers.offer_type', 'product_discount')
                ->orWhere(function (Builder $advanced): void {
                    $advanced->where('offers.offer_type', 'buy_x_get_y')
                        ->where('buy_quantity', '>', 0)->where('reward_quantity', '>', 0)
                        ->whereBetween('reward_discount_percent', [0.01, 100])
                        ->whereHas('products', fn (Builder $p) => RestaurantFoodEligibility::browsableProducts($p)
                            ->whereColumn('products.restaurant_id', 'offers.restaurant_id'))
                        ->where(fn (Builder $mode) => $mode->whereIn('reward_type', ['same_product', 'cheapest_eligible'])
                            ->orWhere(fn (Builder $specific) => $specific->where('reward_type', 'specific_products')
                                ->whereHas('rewardProducts', fn (Builder $p) => RestaurantFoodEligibility::browsableProducts($p)
                                    ->whereColumn('products.restaurant_id', 'offers.restaurant_id'))));
                });
        });
    }

    public static function summary(Offer $offer): array
    {
        return [
            ...$offer->promotionAttributes(),
            'discountType' => $offer->isProductDiscount() ? $offer->discount_type?->value : null,
            'discountValue' => $offer->isProductDiscount() && $offer->discount_value !== null ? number_format((float) $offer->discount_value, 2, '.', '') : null,
        ];
    }

    public static function details(Offer $offer, bool $thumbnail = false): array
    {
        $offer->loadMissing(['restaurant.media', 'products.media', 'rewardProducts.media']);
        $restaurant = $offer->restaurant;
        $identity = $restaurant ? [
            'id' => (int) $restaurant->id,
            'name' => $restaurant->name,
            'imageUrl' => \App\Support\ImageVariants::url($restaurant, 'primary-image', true),
        ] : null;
        $products = fn ($items) => $items->filter(fn (Product $p) => (int) $p->restaurant_id === (int) $offer->restaurant_id
            && $p->isAvailableNow() && $p->isIndependentlyOrderable())
            ->map(fn (Product $p) => ['id' => (int) $p->id, 'name' => $p->name,
                'restaurant' => $identity, 'restaurantId' => (int) $p->restaurant_id,
                'price' => (float) $p->price,
                'currency' => config('app.currency', 'SYP'),
                'isAvailable' => true,
                'offerPrice' => $offer->isProductDiscount() ? number_format(round(max(0, $offer->discount_type?->value === 'percentage'
                    ? (float) $p->price * (1 - min(100, max(0, (float) $offer->discount_value)) / 100)
                    : (float) $p->price - max(0, (float) $offer->discount_value)), 2), 2, '.', '') : null,
                'imageUrl' => \App\Support\ImageVariants::url($p, 'primary-image', $thumbnail)])
            ->values()->all();

        return [...self::summary($offer), 'products' => $products($offer->products),
            'rewardProducts' => $products($offer->rewardProducts)];
    }

    public static function forProduct(Product $product): array
    {
        if (! RestaurantPromotionCapability::supported() || ! $product->isAvailableNow() || ! $product->isIndependentlyOrderable()) {
            return [];
        }
        $now = CarbonImmutable::now();
        $offers = $product->relationLoaded('offers') ? $product->offers : $product->offers()->get();

        return $offers->filter(fn (Offer $o) => ! $o->isProductDiscount()
            && (int) $o->restaurant_id === (int) $product->restaurant_id && $o->effectiveStatus($now) === 'active'
            && $o->buy_quantity > 0 && $o->reward_quantity > 0
            && (float) $o->reward_discount_percent > 0 && (float) $o->reward_discount_percent <= 100
            && ($o->reward_type?->value !== 'specific_products' || $o->rewardProducts->contains(fn (Product $reward) => (int) $reward->restaurant_id === (int) $o->restaurant_id && $reward->isAvailableNow() && $reward->isIndependentlyOrderable())))
            ->map(fn (Offer $o) => ['id' => (int) $o->id, 'title' => $o->name, ...self::summary($o)])
            ->values()->all();
    }
}