<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Support\SupermarketPromotionCapability as Capability;

final class SupermarketPromotionCatalog
{
    public static function constrain($query, bool $upcoming = false)
    {
        $query->where('is_active', true)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now())
                ->when($upcoming, fn ($q) => $q->orWhere(fn ($q) => $q->where('show_upcoming', true)->where('starts_at', '>', now()))));
        if (! Capability::supported()) {
            return $query->where('promotion_type', 'product_discount');
        }

        return $query->where(fn ($q) => $q->where('promotion_type', 'product_discount')->orWhere(fn ($q) => $q->where('promotion_type', 'buy_x_get_y')
            ->whereBetween('buy_quantity', [1, 100])->whereBetween('reward_quantity', [1, 100])->whereBetween('reward_discount_percent', [0.01, 100])
            ->whereHas('offerProducts.product', fn ($p) => self::products($p)->whereColumn('sm_products.store_id', 'sm_offers.store_id'))
            ->where(fn ($mode) => $mode->whereIn('reward_type', ['same_product', 'cheapest_eligible'])->orWhere(fn ($specific) => $specific->where('reward_type', 'specific_products')
                ->whereHas('rewardProducts', fn ($p) => self::products($p)->whereColumn('sm_products.store_id', 'sm_offers.store_id'))))));
    }

    public static function products($query)
    {
        return $query->where('is_available', true)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('stock_quantity')->orWhere('stock_quantity', '>', 0));
    }

    public static function forProduct(SmProduct $product): array
    {
        if (! Capability::supported() || ! $product->is_available) {
            return [];
        }
        if ($product->relationLoaded('offerProducts') && ! $product->offerProducts->contains(fn ($link) => $link->offer?->promotion_type === 'buy_x_get_y')) {
            return [];
        }
        $query = SmOffer::query()->where('store_id', $product->store_id)->where('promotion_type', 'buy_x_get_y')
            ->whereHas('offerProducts', fn ($q) => $q->where('product_id', $product->id));

        return self::constrain($query)->get()->map(fn ($o) => ['id' => (int) $o->id, 'title' => $o->name, ...$o->promotionAttributes()])->all();
    }
}
