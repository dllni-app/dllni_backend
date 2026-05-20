<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Models\SmCategory;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class SmProductSeeder extends Seeder
{
    public function run(): void
    {
        $stores = SmStore::query()
            ->whereIn('slug', [
                'supermarket-al-atrash',
                'supermarket-al-sultan',
                'supermarket-al-noor',
            ])
            ->orWhere('slug', 'like', 'seller-supermarket-%')
            ->get()
            ->keyBy('slug');

        if ($stores->isEmpty()) {
            return;
        }

        $categoriesByStore = SmCategory::query()
            ->whereIn('store_id', $stores->pluck('id'))
            ->get()
            ->groupBy('store_id');

        $masterProductIds = DB::table('master_products')->pluck('id')->all();
        $masterCount = count($masterProductIds);
        $masterCursor = 0;

        $seededProductIds = [];
        $storeBlueprints = $this->productBlueprints($stores->keys()->all());

        foreach ($storeBlueprints as $storeSlug => $categoryBlueprints) {
            $store = $stores->get($storeSlug);
            if ($store === null) {
                continue;
            }

            $categoryMap = $categoriesByStore->get($store->id)?->keyBy('slug');
            if ($categoryMap === null) {
                continue;
            }

            $barcodeCounter = 1;

            foreach ($categoryBlueprints as $categorySlug => $products) {
                $category = $categoryMap->get($categorySlug);
                if ($category === null) {
                    continue;
                }

                foreach ($products as $productData) {
                    $masterProductId = $masterCount > 0
                        ? $masterProductIds[$masterCursor % $masterCount]
                        : null;
                    $masterCursor++;

                    $expiresInDays = $productData['expires_in_days'] ?? null;
                    $expiresAt = is_numeric($expiresInDays)
                        ? now()->addDays((int) $expiresInDays)
                        : null;

                    $product = SmProduct::query()->updateOrCreate(
                        [
                            'store_id' => $store->id,
                            'name' => (string) $productData['name'],
                        ],
                        [
                            'category_id' => $category->id,
                            'master_product_id' => $masterProductId,
                            'barcode' => sprintf('SM-%d-%04d', $store->id, $barcodeCounter),
                            'source_type' => SmProductSource::Manual->value,
                            'description' => $productData['description'] ?? null,
                            'price' => $productData['price'],
                            'discounted_price' => $productData['discounted_price'] ?? null,
                            'stock_quantity' => $productData['stock_quantity'],
                            'low_stock_threshold' => $productData['low_stock_threshold'] ?? 10,
                            'expires_at' => $expiresAt,
                            'is_available' => $productData['is_available'] ?? true,
                        ]
                    );

                    $seededProductIds[] = $product->id;
                    $barcodeCounter++;
                }
            }
        }

        if ($seededProductIds !== []) {
            $this->seedProductImages(array_values(array_unique($seededProductIds)));
        }
    }

    /**
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    private function productBlueprints(array $storeSlugs): array
    {
        $blueprints = [
            'supermarket-al-atrash' => [
                'bakery' => [
                    ['name' => 'خبز عربي أبيض', 'price' => 2.5, 'stock_quantity' => 240],
                    ['name' => 'خبز قمح كامل', 'price' => 3.0, 'stock_quantity' => 160],
                    ['name' => 'كرواسون زبدة', 'price' => 4.5, 'stock_quantity' => 90, 'discounted_price' => 4.0],
                    ['name' => 'توست حبوب 600غ', 'price' => 7.5, 'stock_quantity' => 70],
                    ['name' => 'مناقيش زعتر مجمدة', 'price' => 11.0, 'stock_quantity' => 45],
                ],
                'canned' => [
                    ['name' => 'حمص معلب 400غ', 'price' => 5.0, 'stock_quantity' => 130],
                    ['name' => 'فول مدمس 400غ', 'price' => 4.5, 'stock_quantity' => 120],
                    ['name' => 'ذرة حلوة 340غ', 'price' => 6.0, 'stock_quantity' => 110],
                    ['name' => 'تونة قطع خفيفة 185غ', 'price' => 9.0, 'stock_quantity' => 75, 'discounted_price' => 8.0],
                    ['name' => 'صلصة طماطم 680غ', 'price' => 6.5, 'stock_quantity' => 100],
                ],
                'cleaning' => [
                    ['name' => 'منظف أرضيات ليمون 2 لتر', 'price' => 14.0, 'stock_quantity' => 60],
                    ['name' => 'منظف زجاج 750مل', 'price' => 8.5, 'stock_quantity' => 75],
                    ['name' => 'سائل جلي 1 لتر', 'price' => 9.0, 'stock_quantity' => 95],
                    ['name' => 'كلور معطر 2 لتر', 'price' => 10.0, 'stock_quantity' => 55],
                    ['name' => 'أكياس قمامة كبيرة 50 حبة', 'price' => 12.0, 'stock_quantity' => 40, 'low_stock_threshold' => 12],
                ],
                'dairy' => [
                    ['name' => 'حليب كامل الدسم 1 لتر', 'price' => 6.0, 'stock_quantity' => 180, 'expires_in_days' => 8],
                    ['name' => 'لبن زبادي 500غ', 'price' => 5.5, 'stock_quantity' => 160, 'expires_in_days' => 6],
                    ['name' => 'جبنة بيضاء 500غ', 'price' => 12.0, 'stock_quantity' => 95, 'expires_in_days' => 10],
                    ['name' => 'لبنة كاملة 400غ', 'price' => 9.5, 'stock_quantity' => 85, 'expires_in_days' => 7],
                    ['name' => 'زبدة طبيعية 200غ', 'price' => 7.5, 'stock_quantity' => 50, 'is_available' => false],
                ],
                'snacks' => [
                    ['name' => 'رقائق بطاطا ملح 160غ', 'price' => 6.5, 'stock_quantity' => 120],
                    ['name' => 'بسكويت شاي 12 قطعة', 'price' => 4.0, 'stock_quantity' => 150],
                    ['name' => 'شوكولاتة داكنة 90غ', 'price' => 8.0, 'stock_quantity' => 80],
                    ['name' => 'مكسرات مشكلة 250غ', 'price' => 18.0, 'stock_quantity' => 35, 'low_stock_threshold' => 10],
                    ['name' => 'عصير برتقال 1 لتر', 'price' => 7.0, 'stock_quantity' => 95, 'discounted_price' => 6.0],
                ],
            ],
            'supermarket-al-sultan' => [
                'vegetables' => [
                    ['name' => 'طماطم طازجة 1 كغ', 'price' => 4.5, 'stock_quantity' => 220],
                    ['name' => 'خيار بلدي 1 كغ', 'price' => 4.0, 'stock_quantity' => 180],
                    ['name' => 'بطاطا 2 كغ', 'price' => 6.0, 'stock_quantity' => 200],
                    ['name' => 'جزر طازج 1 كغ', 'price' => 5.0, 'stock_quantity' => 120],
                    ['name' => 'خس روماني', 'price' => 3.5, 'stock_quantity' => 90],
                    ['name' => 'بصل يابس 1 كغ', 'price' => 4.5, 'stock_quantity' => 145],
                ],
                'fruits' => [
                    ['name' => 'تفاح أحمر 1 كغ', 'price' => 8.5, 'stock_quantity' => 160],
                    ['name' => 'موز مستورد 1 كغ', 'price' => 7.5, 'stock_quantity' => 150],
                    ['name' => 'برتقال عصير 1 كغ', 'price' => 6.5, 'stock_quantity' => 140],
                    ['name' => 'عنب أخضر 1 كغ', 'price' => 11.0, 'stock_quantity' => 85, 'discounted_price' => 9.5],
                    ['name' => 'فراولة 500غ', 'price' => 9.0, 'stock_quantity' => 65, 'low_stock_threshold' => 15],
                ],
                'dairy' => [
                    ['name' => 'حليب قليل الدسم 1 لتر', 'price' => 6.0, 'stock_quantity' => 130, 'expires_in_days' => 8],
                    ['name' => 'لبن رائب 1 لتر', 'price' => 6.5, 'stock_quantity' => 120, 'expires_in_days' => 6],
                    ['name' => 'جبنة موزاريلا 200غ', 'price' => 12.5, 'stock_quantity' => 70, 'expires_in_days' => 12],
                    ['name' => 'جبنة شيدر شرائح 180غ', 'price' => 10.5, 'stock_quantity' => 75, 'expires_in_days' => 14],
                    ['name' => 'قشطة طبخ 250مل', 'price' => 8.0, 'stock_quantity' => 60, 'is_available' => false],
                ],
                'cleaning' => [
                    ['name' => 'مسحوق غسيل ملابس 3 كغ', 'price' => 24.0, 'stock_quantity' => 55],
                    ['name' => 'مطهر متعدد الاستخدام 1 لتر', 'price' => 11.0, 'stock_quantity' => 80],
                    ['name' => 'مناديل مبللة مطهرة 80 قطعة', 'price' => 9.5, 'stock_quantity' => 95],
                    ['name' => 'صابون يدين رغوي 500مل', 'price' => 7.5, 'stock_quantity' => 100],
                    ['name' => 'فوط تنظيف ميكروفايبر', 'price' => 13.0, 'stock_quantity' => 40],
                ],
                'household' => [
                    ['name' => 'ورق ألمنيوم 30 متر', 'price' => 8.0, 'stock_quantity' => 100],
                    ['name' => 'أكواب ورقية 50 حبة', 'price' => 7.0, 'stock_quantity' => 85],
                    ['name' => 'أطباق بلاستيك 25 حبة', 'price' => 6.5, 'stock_quantity' => 92],
                    ['name' => 'إسفنج مطبخ 6 قطع', 'price' => 5.0, 'stock_quantity' => 110],
                    ['name' => 'قماش مائدة للاستعمال مرة', 'price' => 4.5, 'stock_quantity' => 70, 'discounted_price' => 4.0],
                ],
            ],
            'supermarket-al-noor' => [
                'canned' => [
                    ['name' => 'فاصولياء حمراء 400غ', 'price' => 5.5, 'stock_quantity' => 95],
                    ['name' => 'عدس معلب 400غ', 'price' => 5.0, 'stock_quantity' => 90],
                    ['name' => 'بازلاء مع جزر 340غ', 'price' => 6.0, 'stock_quantity' => 85],
                    ['name' => 'ذرة معلبة 340غ', 'price' => 6.0, 'stock_quantity' => 78],
                    ['name' => 'صلصة بيتزا 400غ', 'price' => 7.0, 'stock_quantity' => 72],
                ],
                'cleaning' => [
                    ['name' => 'منظف حمام 900مل', 'price' => 10.0, 'stock_quantity' => 68],
                    ['name' => 'سائل تعقيم أسطح 750مل', 'price' => 9.0, 'stock_quantity' => 74],
                    ['name' => 'فوط مطبخ رول مزدوج', 'price' => 12.0, 'stock_quantity' => 58],
                    ['name' => 'أكياس طعام صغيرة 100 حبة', 'price' => 6.0, 'stock_quantity' => 120],
                    ['name' => 'معطر جو 300مل', 'price' => 8.5, 'stock_quantity' => 66],
                ],
                'dairy' => [
                    ['name' => 'حليب خالي الدسم 1 لتر', 'price' => 6.0, 'stock_quantity' => 110, 'expires_in_days' => 7],
                    ['name' => 'لبن يوناني 170غ', 'price' => 4.0, 'stock_quantity' => 95, 'expires_in_days' => 5],
                    ['name' => 'جبنة قشقوان 300غ', 'price' => 14.0, 'stock_quantity' => 48, 'expires_in_days' => 11],
                    ['name' => 'لبنة قليلة الدسم 400غ', 'price' => 9.0, 'stock_quantity' => 60, 'expires_in_days' => 6],
                    ['name' => 'حليب شوكولاتة 250مل', 'price' => 3.5, 'stock_quantity' => 130, 'expires_in_days' => 9],
                ],
                'snacks' => [
                    ['name' => 'بسكويت دايجستف 250غ', 'price' => 5.0, 'stock_quantity' => 115],
                    ['name' => 'لوح شوكولاتة بالحليب 90غ', 'price' => 6.0, 'stock_quantity' => 105],
                    ['name' => 'فشار جاهز 80غ', 'price' => 4.0, 'stock_quantity' => 125],
                    ['name' => 'عصير تفاح 1 لتر', 'price' => 7.0, 'stock_quantity' => 88],
                    ['name' => 'مياه معدنية 1.5 لتر', 'price' => 1.5, 'stock_quantity' => 260],
                ],
            ],
        ];

        foreach ($storeSlugs as $storeSlug) {
            if (! str_starts_with($storeSlug, 'seller-supermarket-')) {
                continue;
            }

            $blueprints[$storeSlug] = [
                'syrian-essentials' => [
                    ['name' => 'رز قصير الحبة 1 كغ', 'price' => 18000, 'stock_quantity' => 130],
                    ['name' => 'برغل ناعم 1 كغ', 'price' => 12000, 'stock_quantity' => 110],
                    ['name' => 'عدس أحمر 1 كغ', 'price' => 15000, 'stock_quantity' => 95],
                    ['name' => 'سكر أبيض 1 كغ', 'price' => 9500, 'stock_quantity' => 180],
                    ['name' => 'زيت دوار الشمس 1.8 لتر', 'price' => 34000, 'stock_quantity' => 85, 'discounted_price' => 31500],
                    ['name' => 'معجون طماطم 660 غ', 'price' => 8000, 'stock_quantity' => 125],
                ],
                'syrian-dairy' => [
                    ['name' => 'لبنة بلدية 500 غ', 'price' => 19000, 'stock_quantity' => 75, 'expires_in_days' => 6],
                    ['name' => 'جبنة شلل 500 غ', 'price' => 28000, 'stock_quantity' => 60, 'expires_in_days' => 8],
                    ['name' => 'حليب كامل الدسم 1 لتر', 'price' => 10000, 'stock_quantity' => 120, 'expires_in_days' => 7],
                    ['name' => 'لبن رائب 1 لتر', 'price' => 11500, 'stock_quantity' => 95, 'expires_in_days' => 5],
                    ['name' => 'جبنة قشقوان 300 غ', 'price' => 26000, 'stock_quantity' => 45, 'expires_in_days' => 10],
                ],
                'syrian-drinks' => [
                    ['name' => 'متة 500 غ', 'price' => 23000, 'stock_quantity' => 90],
                    ['name' => 'شاي سيلاني 400 غ', 'price' => 17000, 'stock_quantity' => 100],
                    ['name' => 'عصير تمر هندي 1 لتر', 'price' => 9500, 'stock_quantity' => 85],
                    ['name' => 'مياه معدنية 1.5 لتر', 'price' => 4000, 'stock_quantity' => 220],
                    ['name' => 'شراب توت الشام 750 مل', 'price' => 13500, 'stock_quantity' => 70],
                ],
                'syrian-snacks' => [
                    ['name' => 'بسكويت شاي سادة 12 قطعة', 'price' => 6500, 'stock_quantity' => 140],
                    ['name' => 'شوكولا محشية بندق 90 غ', 'price' => 8500, 'stock_quantity' => 105],
                    ['name' => 'بزر دوار الشمس محمص 250 غ', 'price' => 14000, 'stock_quantity' => 65],
                    ['name' => 'راحة حلقوم بالفستق 400 غ', 'price' => 21000, 'stock_quantity' => 55, 'discounted_price' => 19500],
                    ['name' => 'رقائق بطاطا حارة 160 غ', 'price' => 7000, 'stock_quantity' => 120],
                ],
                'syrian-cleaning' => [
                    ['name' => 'سائل جلي ليمون 1 لتر', 'price' => 9500, 'stock_quantity' => 100],
                    ['name' => 'مسحوق غسيل 2.5 كغ', 'price' => 36000, 'stock_quantity' => 50],
                    ['name' => 'كلور معطر 2 لتر', 'price' => 11500, 'stock_quantity' => 80],
                    ['name' => 'أكياس قمامة متوسطة 50 كيس', 'price' => 10000, 'stock_quantity' => 90],
                    ['name' => 'مناديل ورقية 10 عبوات', 'price' => 18500, 'stock_quantity' => 70],
                ],
            ];
        }

        return $blueprints;
    }

    /**
     * @param  array<int, int>  $productIds
     */
    private function seedProductImages(array $productIds): void
    {
        $items = SmProduct::query()->whereIn('id', $productIds)->get();

        foreach ($items as $product) {
            if ($product->getFirstMedia(SmProduct::IMAGE_COLLECTION) !== null) {
                continue;
            }

            $seed = (string) $product->id;

            SeederMedia::ensureSingleMedia(
                $product,
                SmProduct::IMAGE_COLLECTION,
                'https://images.unsplash.com/photo-1583258292688-d0213dc5a3a8?auto=format&fit=crop&w=800&q=80',
                "sm-product-{$seed}"
            );
        }
    }
}
