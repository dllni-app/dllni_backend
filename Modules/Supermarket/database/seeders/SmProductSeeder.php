<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use App\Models\MasterProduct;
use Illuminate\Database\Seeder;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class SmProductSeeder extends Seeder
{
    public function run(): void
    {
        $productTemplates = [
            'vegetables-fruits' => [
                ['name' => 'طماطم طازجة', 'price' => 2.50, 'barcode' => null],
                ['name' => 'خيار', 'price' => 1.80, 'barcode' => null],
                ['name' => 'بطاطا', 'price' => 1.20, 'barcode' => null],
                ['name' => 'بصل', 'price' => 1.50, 'barcode' => null],
                ['name' => 'تفاح أحمر', 'price' => 3.00, 'barcode' => null],
                ['name' => 'موز', 'price' => 2.00, 'barcode' => null],
                ['name' => 'برتقال', 'price' => 2.20, 'barcode' => null],
            ],
            'dairy-cheese' => [
                ['name' => 'حليب كامل الدسم', 'price' => 1.80, 'barcode' => null],
                ['name' => 'لبن طبيعي', 'price' => 1.50, 'barcode' => null],
                ['name' => 'جبنة بيضاء', 'price' => 4.50, 'barcode' => null],
                ['name' => 'جبنة شيدر', 'price' => 5.00, 'barcode' => null],
                ['name' => 'زبادي طبيعي', 'price' => 2.00, 'barcode' => null],
            ],
            'meat-poultry' => [
                ['name' => 'صدر دجاج', 'price' => 8.50, 'barcode' => null],
                ['name' => 'لحم مفروم', 'price' => 12.00, 'barcode' => null],
                ['name' => 'سمك فيليه', 'price' => 15.00, 'barcode' => null],
            ],
            'bakery-sweets' => [
                ['name' => 'خبز عربي', 'price' => 0.80, 'barcode' => null],
                ['name' => 'كرواسون', 'price' => 2.50, 'barcode' => null],
                ['name' => 'كيك شوكولاتة', 'price' => 6.00, 'barcode' => null],
            ],
            'canned-frozen' => [
                ['name' => 'فول معلب', 'price' => 1.20, 'barcode' => null],
                ['name' => 'ذرة مجمدة', 'price' => 2.50, 'barcode' => null],
                ['name' => 'معجون طماطم', 'price' => 1.80, 'barcode' => null],
            ],
            'beverages' => [
                ['name' => 'عصير برتقال', 'price' => 2.50, 'barcode' => null],
                ['name' => 'ماء معدني', 'price' => 0.50, 'barcode' => null],
                ['name' => 'مشروب غازي', 'price' => 1.20, 'barcode' => null],
                ['name' => 'حليب لوز', 'price' => 3.00, 'barcode' => null],
            ],
            'cleaning-household' => [
                ['name' => 'صابون سائل', 'price' => 2.00, 'barcode' => null],
                ['name' => 'مناديل مطبخ', 'price' => 1.50, 'barcode' => null],
                ['name' => 'كلور تنظيف', 'price' => 1.80, 'barcode' => null],
            ],
        ];

        $masterProducts = MasterProduct::all()->keyBy('id');

        SmStore::with('categories')->each(function (SmStore $store) use ($productTemplates, $masterProducts): void {
            foreach ($store->categories as $category) {
                $templates = $productTemplates[$category->slug] ?? [];
                foreach ($templates as $t) {
                    $masterProductId = null;
                    $master = $masterProducts->first(fn (MasterProduct $mp) => mb_stripos($mp->name, explode(' ', $t['name'])[0]) !== false);
                    if ($master) {
                        $masterProductId = $master->id;
                    }

                    SmProduct::firstOrCreate(
                        [
                            'store_id' => $store->id,
                            'category_id' => $category->id,
                            'name' => $t['name'],
                        ],
                        [
                            'master_product_id' => $masterProductId,
                            'barcode' => $t['barcode'],
                            'source_type' => SmProductSource::Manual->value,
                            'description' => null,
                            'price' => $t['price'],
                            'discounted_price' => null,
                            'stock_quantity' => fake()->numberBetween(20, 200),
                            'low_stock_threshold' => 10,
                            'is_available' => true,
                        ]
                    );
                }
            }
        });
    }
}
