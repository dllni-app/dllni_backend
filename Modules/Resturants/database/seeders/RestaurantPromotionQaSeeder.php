<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\RestaurantFoodEligibility;

/** Explicit local QA only; never called by DatabaseSeeder. */
final class RestaurantPromotionQaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || config('database.default') !== 'mysql'
            || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || DB::connection()->getDatabaseName() !== 'alnadha_worker_test'
            || DB::selectOne('SELECT DATABASE() AS db')->db !== 'alnadha_worker_test') {
            throw new LogicException('Restaurant promotion QA requires the existing local QA database.');
        }

        DB::transaction(function (): void {
            $eligible = [];
            foreach (Restaurant::query()->where('is_active', true)->orderBy('id')->get() as $restaurant) {
                $products = RestaurantFoodEligibility::browsableProducts(Product::query()->where('restaurant_id', $restaurant->id))
                    ->where('classification_status', 'reviewed')->where('price', '>', 0)->orderBy('id')->get();
                $meals = $products->whereIn('product_kind', ['main_dish', 'meal', 'combo'])->values();
                $drink = $products->firstWhere('product_kind', 'beverage');
                if ($meals->count() >= 2 && $drink !== null) {
                    $eligible[] = [$restaurant, $meals, $drink];
                }
                if (count($eligible) === 3) {
                    break;
                }
            }
            if (count($eligible) < 3) {
                throw new LogicException('Three reviewed QA restaurants with two meals and a beverage are required.');
            }

            foreach ($eligible as $index => [$restaurant, $meals, $drink]) {
                $mode = ['same_product', 'cheapest_eligible', 'specific_products'][$index];
                $name = ['[QA-PROMO] اشترِ 2 والثالث مجاناً', '[QA-PROMO] اشترِ وجبتين والأرخص مجاناً', '[QA-PROMO] اشترِ وجبة واحصل على مشروب مجاناً'][$index];
                $offer = Offer::query()->firstOrNew(['restaurant_id' => $restaurant->id, 'name' => $name]);
                $offer->fill([
                    'description' => 'عرض تجريبي محلي لاختبار احتساب المكافآت والإضافات المدفوعة.',
                    'discount_type' => 'fixed_amount', 'discount_value' => 0,
                    'offer_type' => 'buy_x_get_y', 'buy_quantity' => $index === 2 ? 1 : 2,
                    'reward_quantity' => 1, 'reward_type' => $mode, 'reward_discount_percent' => 100,
                    'is_repeatable' => true, 'max_applications_per_order' => 3,
                    'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(14), 'is_active' => true,
                    'rule_version' => $offer->exists ? ((int) $offer->rule_version + 1) : 1,
                ])->save();
                $offer->products()->sync($index === 1 ? $meals->take(2)->pluck('id')->all() : [$meals->first()->id]);
                $offer->rewardProducts()->sync($index === 2 ? [$drink->id] : []);
                $this->command?->info("QA promotion {$offer->id}: restaurant {$restaurant->id}, {$mode}");
            }
        });
    }
}
