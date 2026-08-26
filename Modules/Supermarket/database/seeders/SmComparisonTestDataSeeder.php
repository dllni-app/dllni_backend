<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use App\Models\MasterProduct;
use Illuminate\Database\Seeder;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Models\SmCategory;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class SmComparisonTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $stores = SmStore::query()
            ->whereIn('slug', [
                'supermarket-al-atrash',
                'supermarket-al-sultan',
                'supermarket-al-noor',
            ])
            ->get()
            ->keyBy('slug');

        if ($stores->count() < 2) {
            return;
        }

        $masterProducts = MasterProduct::query()
            ->whereIn('name', [
                'حليب كامل الدسم',
                'حليب قليل الدسم',
                'جبنة موزاريلا',
                'لبنة بلدية',
            ])
            ->get()
            ->keyBy('name');

        $rows = [
            'supermarket-al-atrash' => [
                ['master' => 'حليب كامل الدسم', 'name' => 'حليب كامل الدسم 1 لتر - المراعي', 'price' => 50, 'discounted_price' => 25],
                ['master' => 'حليب قليل الدسم', 'name' => 'حليب قليل الدسم 1 لتر - المراعي', 'price' => 25, 'discounted_price' => null],
                ['master' => 'جبنة موزاريلا', 'name' => 'جبنة موزاريلا 200غ - برايد', 'price' => 100, 'discounted_price' => null],
                ['master' => 'لبنة بلدية', 'name' => 'لبنة بلدية 400غ - الصفدي', 'price' => 50, 'discounted_price' => null],
            ],
            'supermarket-al-sultan' => [
                ['master' => 'حليب كامل الدسم', 'name' => 'حليب كامل الدسم 1 لتر اقتصادي', 'price' => 25, 'discounted_price' => null],
                ['master' => 'حليب قليل الدسم', 'name' => 'حليب قليل الدسم 1 لتر عرض السلطان', 'price' => 50, 'discounted_price' => 25],
                ['master' => 'جبنة موزاريلا', 'name' => 'جبنة موزاريلا 200غ طازجة', 'price' => 50, 'discounted_price' => null],
                ['master' => 'لبنة بلدية', 'name' => 'لبنة بلدية 400غ يومية', 'price' => 25, 'discounted_price' => null],
            ],
            'supermarket-al-noor' => [
                ['master' => 'حليب كامل الدسم', 'name' => 'حليب كامل الدسم 1 لتر عائلي', 'price' => 100, 'discounted_price' => 50],
                ['master' => 'حليب قليل الدسم', 'name' => 'حليب قليل الدسم 1 لتر خيار صحي', 'price' => 100, 'discounted_price' => null],
                ['master' => 'جبنة موزاريلا', 'name' => 'جبنة موزاريلا 200غ عرض النور', 'price' => 100, 'discounted_price' => 50],
                ['master' => 'لبنة بلدية', 'name' => 'لبنة بلدية 400غ عائلية', 'price' => 100, 'discounted_price' => 50],
            ],
        ];

        foreach ($rows as $storeSlug => $products) {
            $store = $stores->get($storeSlug);
            if ($store === null) {
                continue;
            }

            $category = SmCategory::query()
                ->where('store_id', $store->id)
                ->where('slug', 'dairy')
                ->first();

            if ($category === null) {
                continue;
            }

            foreach ($products as $index => $productData) {
                $masterProduct = $masterProducts->get($productData['master']);
                if ($masterProduct === null) {
                    continue;
                }

                SmProduct::query()->updateOrCreate(
                    [
                        'store_id' => $store->id,
                        'name' => $productData['name'],
                    ],
                    [
                        'category_id' => $category->id,
                        'master_product_id' => $masterProduct->id,
                        'barcode' => sprintf('CMP-%d-%02d', $store->id, $index + 1),
                        'source_type' => SmProductSource::Manual->value,
                        'description' => 'منتج تجريبي واقعي لاختبار مقارنة نفس المنتج بين عدة متاجر.',
                        'price' => $productData['price'],
                        'discounted_price' => $productData['discounted_price'],
                        'stock_quantity' => 100,
                        'low_stock_threshold' => 10,
                        'expires_at' => now()->addDays(10),
                        'is_available' => true,
                    ]
                );
            }
        }
    }
}
