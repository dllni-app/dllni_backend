<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;

/**
 * Opt-in, local-only editorial review of the existing known QA menu.
 * Never invoke as part of general or production seeding.
 */
final class RestaurantFoodQaClassificationSeeder extends Seeder
{
    public function run(): void
    {
        if (config('app.env') !== 'local'
            || config('database.default') !== 'mysql'
            || ! in_array((string) DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || DB::connection()->getDatabaseName() !== 'alnadha_worker_test') {
            throw new LogicException('Food QA classification is restricted to the existing local QA database.');
        }

        $map = [
            'دجاج مشوي على الفحم' => ['main_dish', 'grill'],
            'ستيك لحم بقري' => ['main_dish', 'grill'],
            'باستا كاربونارا' => ['main_dish', 'pasta'],
            'سلطة سيزر' => ['side_dish', 'sides'],
            'خبز بالثوم' => ['side_dish', 'sides'],
            'كاليماري مقرمش' => ['side_dish', 'sides'],
            'كيك شوكولاتة' => ['dessert', 'desserts'],
            'تيراميسو' => ['dessert', 'desserts'],
            'آيس كريم فانيلا' => ['dessert', 'desserts'],
            'شوربة اليوم' => ['side_dish', 'sides'],
            'بروشيتا' => ['side_dish', 'sides'],
            'دجاج مشوي' => ['main_dish', 'grill'],
            'برغر لحم' => ['main_dish', 'burger'],
            'ستيك ومرافقات' => ['main_dish', 'grill'],
            'آيس كريم' => ['dessert', 'desserts'],
            'عصير طازج' => ['beverage', 'drinks'],
            'مشروب غازي' => ['beverage', 'drinks'],
            'شاي' => ['beverage', 'drinks'],
            'ماء' => ['beverage', 'drinks'],
        ];

        $categories = FoodDiscoveryCategory::query()->pluck('id', 'slug');
        DB::transaction(function () use ($map, $categories): void {
            $products = Product::query()->with('category')->orderBy('id')->get();
            if ($products->count() !== 84) {
                throw new LogicException('Unexpected local QA catalog. Manual review required.');
            }
            foreach ($products as $product) {
                if (! isset($map[$product->name])) {
                    throw new LogicException('Unknown QA product requires editorial review: '.$product->id);
                }

                [$kind, $slug] = $map[$product->name];
                if (! isset($categories[$slug])) {
                    throw new LogicException('Missing food type '.$slug);
                }

                // Only the pre-inspected local seed catalogue is eligible.
                // A garlic-bread appetizer is an independent side, unlike garlic sauce modifiers.
                if ((int) $product->restaurant_id < 1 || (int) $product->restaurant_id > 6
                    || (int) $product->id < 1 || (int) $product->id > 84
                    || $product->category === null) {
                    throw new LogicException('QA record identity changed; do not classify automatically.');
                }

                if ($product->classification_status !== 'unreviewed') {
                    continue; // Never overwrite an editorial classification.
                }

                $product->forceFill([
                    'catalog_role' => 'independent',
                    'product_kind' => $kind,
                    'primary_food_category_id' => $categories[$slug],
                    'classification_status' => 'reviewed',
                    'classified_at' => now(),
                ])->save();
            }
        });
    }
}
