<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class SmOfferSeeder extends Seeder
{
    public function run(): void
    {
        SmDemoCatalog::load();
        $stores = SmStore::query()
            ->whereIn('slug', ['supermarket-al-atrash', 'supermarket-al-sultan', 'supermarket-al-noor'])
            ->orWhere('slug', 'like', 'seller-supermarket-%')
            ->get();

        foreach ($stores as $store) {
            $legacy = match ($store->slug) {
                'supermarket-al-atrash' => ['عروض استثنائية', 30],
                'supermarket-al-sultan' => ['خصم 20%', 20],
                'supermarket-al-noor' => ['خصم 15%', 15],
                default => ['خصم على المواد الأساسية', 15],
            };

            // Existing legacy offers keep their IDs; use the same vocabulary
            // consumed by SmProduct::effectivePrice() and the promotion engine.
            $this->seed($store, $legacy[0], 'خصم على الأرز والعدس والحمص المحددة في العرض.', ['rice', 'lentils', 'chickpeas'], [
                'offer_type' => 'percent',
                'discount_percent' => $legacy[1],
            ]);
            $this->seed($store, 'خصم 25 على المنظفات', 'وفر 25 ليرة على منتجات التنظيف المحددة.', ['laundry-new', 'cleaner'], [
                'offer_type' => 'value',
                'discount_value' => 25,
            ]);
            $this->seed($store, 'اشتر 2 مياه واحصل على الثالثة مجاناً', 'عرض متكرر على عبوات المياه 500 مل.', ['water-new'], $this->reward('same_product', 2, 1, 100, true, 3));
            $this->seed($store, 'اشتر 2 عصير واحصل على الأرخص مجاناً', 'اختر من عصير التفاح والبرتقال؛ العبوة الأرخص مكافأة.', ['orange-juice', 'apple-juice'], $this->reward('cheapest_eligible', 2, 1, 100, true, 2));
            $this->seed($store, 'اشتر 2 معكرونة واحصل على رب البندورة مجاناً', 'اختر المعكرونة ثم أضف عبوة رب البندورة كمكافأة.', ['pasta', 'macaroni'], $this->reward('specific_products', 2, 1, 100, false), ['tomato-paste']);
            $this->seed($store, 'اشتر 1 شيبس واحصل على الثاني بنصف السعر', 'خصم 50% على القطعة الثانية، مرة واحدة في الطلب.', ['chips-new'], $this->reward('same_product', 1, 1, 50, false, 1));
            $this->seed($store, 'عرض الفطور القادم', 'عرض مجدول يبدأ غداً على الشوفان ورقائق الذرة.', ['oats', 'cereal'], [
                'offer_type' => 'percent', 'discount_percent' => 20,
                'starts_at' => today()->addDay(), 'ends_at' => today()->addDays(8), 'show_upcoming' => true,
            ]);
            $this->seed($store, 'عرض القهوة القادم', 'عرض شراء ومكافأة مجدول يبدأ غداً.', ['coffee'], [
                ...$this->reward('same_product', 2, 1, 100, true),
                'starts_at' => today()->addDay(), 'ends_at' => today()->addDays(8), 'show_upcoming' => true,
            ]);
            $this->seed($store, 'عرض الشاي المنتهي', 'عرض منتهٍ لا يظهر ضمن العروض المتاحة.', ['tea'], [
                'offer_type' => 'percent', 'discount_percent' => 30,
                'starts_at' => today()->subDays(8), 'ends_at' => today()->subDay(),
            ]);
            $this->seed($store, 'عرض العناية الموقوف', 'عرض متوقف لأغراض اختبار لوحة إدارة العروض.', ['shampoo'], [
                'offer_type' => 'value', 'discount_value' => 10, 'is_active' => false,
            ]);
            $this->seed($store, 'اشتر 2 حفاضات واحصل على مناديل بنصف السعر', 'مكافأة منفصلة بخصم 50%، حتى مرتين في الطلب.', ['diapers'], $this->reward('specific_products', 2, 1, 50, true, 2), ['baby-wipes']);
            $this->seed($store, 'خصم 100% على ويفر البندق', 'عرض تجريبي لاختبار السعر النهائي صفر.', ['wafer'], [
                'offer_type' => 'percent', 'discount_percent' => 100,
            ]);
        }
    }

    private function reward(string $type, int $buy, int $quantity, int $percent, bool $repeatable, ?int $cap = null): array
    {
        return [
            'promotion_type' => 'buy_x_get_y',
            // Same legacy compatibility values as SmOfferService.
            'offer_type' => 'value', 'discount_value' => 0,
            'buy_quantity' => $buy, 'reward_quantity' => $quantity,
            'reward_type' => $type, 'reward_discount_percent' => $percent,
            'is_repeatable' => $repeatable, 'max_applications_per_order' => $cap,
        ];
    }

    private function seed(SmStore $store, string $name, string $description, array $products, array $attributes, array $rewards = []): void
    {
        DB::transaction(function () use ($store, $name, $description, $products, $attributes, $rewards): void {
            $qualifierIds = $this->productIds($store, $products);
            $rewardIds = $this->productIds($store, $rewards);

            $offer = SmOffer::query()->firstOrNew(['store_id' => $store->id, 'name' => $name]);
            $previousQualifiers = $offer->exists ? $offer->offerProducts()->pluck('product_id')->map(fn ($id) => (int) $id)->sort()->values()->all() : [];
            $previousRewards = $offer->exists ? $offer->rewardProducts()->pluck('sm_products.id')->map(fn ($id) => (int) $id)->sort()->values()->all() : [];
            $offer->fill([
                'description' => $description,
                'promotion_type' => 'product_discount',
                'offer_type' => 'percent',
                'discount_value' => null, 'discount_percent' => null,
                'buy_quantity' => null, 'reward_quantity' => null, 'reward_type' => null,
                'reward_discount_percent' => null, 'is_repeatable' => false,
                'max_applications_per_order' => null,
                'starts_at' => today()->subDay(), 'ends_at' => today()->addDays(10),
                'is_active' => true, 'show_upcoming' => false,
                ...$attributes,
            ]);

            // The model increments the version when attributes change; also
            // invalidate old quotes when only the fixture's product scope changed.
            if ($offer->exists && ! $offer->isDirty() && ($previousQualifiers !== $qualifierIds || $previousRewards !== $rewardIds)) {
                $offer->rule_version = (int) $offer->rule_version + 1;
            }
            $offer->save();
            $offer->offerProducts()->whereNotIn('product_id', $qualifierIds)->delete();
            foreach ($qualifierIds as $productId) {
                $offer->offerProducts()->updateOrCreate(['product_id' => $productId], ['offer_price' => null, 'max_quantity' => null]);
            }
            $offer->rewardProducts()->sync($rewardIds);
        });
    }

    private function productIds(SmStore $store, array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $product = SmProduct::query()->where('store_id', $store->id)
                ->where('barcode', sprintf('SM-%d-DEMO-%s', $store->id, $key))
                ->where('is_available', true)->where('stock_quantity', '>', 0)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();
            if ($product === null) {
                throw new LogicException('Missing eligible supermarket fixture: '.$store->slug.'/'.$key);
            }
            $ids[] = (int) $product->id;
        }
        sort($ids);
        return $ids;
    }
}
