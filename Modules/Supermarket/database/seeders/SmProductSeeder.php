<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Models\SmProduct;

final class SmProductSeeder extends Seeder
{
    public function run(): void
    {
        $existingMasterProductIds = array_flip(DB::table('master_products')->pluck('id')->all());

        $products = [
            ['id' => 1, 'store_id' => 1, 'category_id' => 1, 'master_product_id' => 1, 'name' => 'حليب كامل الدسم 1 لتر', 'price' => 6, 'discounted_price' => null, 'stock_quantity' => 200, 'is_available' => true],
            ['id' => 2, 'store_id' => 1, 'category_id' => 2, 'master_product_id' => 4, 'name' => 'رز بسمتي 5 كغ', 'price' => 28, 'discounted_price' => 25, 'stock_quantity' => 100, 'is_available' => true],
            ['id' => 3, 'store_id' => 1, 'category_id' => 3, 'master_product_id' => 5, 'name' => 'دجاج طازج كامل', 'price' => 22, 'discounted_price' => null, 'stock_quantity' => 50, 'is_available' => true],
            ['id' => 4, 'store_id' => 1, 'category_id' => 4, 'master_product_id' => 6, 'name' => 'طماطم طازجة', 'price' => 4, 'discounted_price' => null, 'stock_quantity' => 150, 'is_available' => true],
            ['id' => 5, 'store_id' => 1, 'category_id' => 4, 'master_product_id' => 7, 'name' => 'خيار طازج', 'price' => 3, 'discounted_price' => null, 'stock_quantity' => 120, 'is_available' => true],
            ['id' => 6, 'store_id' => 1, 'category_id' => 5, 'master_product_id' => 10, 'name' => 'معكرونة سباغيتي 500غ', 'price' => 7, 'discounted_price' => null, 'stock_quantity' => 90, 'is_available' => true],
            ['id' => 7, 'store_id' => 1, 'category_id' => 1, 'master_product_id' => 8, 'name' => 'جبنة موزاريلا 200غ', 'price' => 12, 'discounted_price' => 10, 'stock_quantity' => 60, 'is_available' => true],
            ['id' => 8, 'store_id' => 1, 'category_id' => 2, 'master_product_id' => 11, 'name' => 'زيت زيتون 1 لتر', 'price' => 35, 'discounted_price' => null, 'stock_quantity' => 80, 'is_available' => true],
            ['id' => 9, 'store_id' => 1, 'category_id' => 1, 'master_product_id' => 12, 'name' => 'لبن زبادي 500غ', 'price' => 5, 'discounted_price' => 4.5, 'stock_quantity' => 150, 'is_available' => true],
            ['id' => 10, 'store_id' => 1, 'category_id' => 3, 'master_product_id' => 13, 'name' => 'لحم بقري 1 كغ', 'price' => 45, 'discounted_price' => null, 'stock_quantity' => 30, 'is_available' => true],
            ['id' => 11, 'store_id' => 1, 'category_id' => 4, 'master_product_id' => 14, 'name' => 'بطاطا 2 كغ', 'price' => 5, 'discounted_price' => null, 'stock_quantity' => 200, 'is_available' => true],
            ['id' => 12, 'store_id' => 1, 'category_id' => 4, 'master_product_id' => 15, 'name' => 'جزر طازج 1 كغ', 'price' => 4, 'discounted_price' => null, 'stock_quantity' => 100, 'is_available' => true],
            ['id' => 13, 'store_id' => 1, 'category_id' => 5, 'master_product_id' => 16, 'name' => 'خبز أبيض', 'price' => 2, 'discounted_price' => null, 'stock_quantity' => 250, 'is_available' => true],
            ['id' => 14, 'store_id' => 1, 'category_id' => 5, 'master_product_id' => 17, 'name' => 'سكر 2 كغ', 'price' => 8, 'discounted_price' => 7, 'stock_quantity' => 180, 'is_available' => true],
            ['id' => 15, 'store_id' => 1, 'category_id' => 1, 'master_product_id' => 18, 'name' => 'بيض طازج 12 حبة', 'price' => 10, 'discounted_price' => null, 'stock_quantity' => 120, 'is_available' => true],
            ['id' => 16, 'store_id' => 1, 'category_id' => 2, 'master_product_id' => 19, 'name' => 'معجون طماطم 400غ', 'price' => 6, 'discounted_price' => null, 'stock_quantity' => 140, 'is_available' => true],
            ['id' => 17, 'store_id' => 1, 'category_id' => 3, 'master_product_id' => 20, 'name' => 'سمك فيليه 500غ', 'price' => 30, 'discounted_price' => 28, 'stock_quantity' => 40, 'is_available' => true],
            ['id' => 18, 'store_id' => 1, 'category_id' => 4, 'master_product_id' => 21, 'name' => 'فلفل رومي ملون', 'price' => 7, 'discounted_price' => null, 'stock_quantity' => 90, 'is_available' => true],
            ['id' => 19, 'store_id' => 1, 'category_id' => 5, 'master_product_id' => 22, 'name' => 'ملح طعام 1 كغ', 'price' => 3, 'discounted_price' => null, 'stock_quantity' => 200, 'is_available' => true],
            ['id' => 20, 'store_id' => 1, 'category_id' => 1, 'master_product_id' => 23, 'name' => 'جبنة بيضاء 250غ', 'price' => 8, 'discounted_price' => null, 'stock_quantity' => 70, 'is_available' => true],
        ];

        foreach ($products as $product) {
            $masterProductId = isset($existingMasterProductIds[$product['master_product_id']])
                ? $product['master_product_id']
                : null;

            DB::table('sm_products')->updateOrInsert(
                ['id' => $product['id']],
                [
                    'store_id' => $product['store_id'],
                    'category_id' => $product['category_id'],
                    'master_product_id' => $masterProductId,
                    'name' => $product['name'],
                    'barcode' => null,
                    'source_type' => SmProductSource::Manual->value,
                    'description' => null,
                    'price' => $product['price'],
                    'discounted_price' => $product['discounted_price'],
                    'stock_quantity' => $product['stock_quantity'],
                    'low_stock_threshold' => 10,
                    'expires_at' => null,
                    'is_available' => $product['is_available'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->seedProductImages(array_column($products, 'id'));
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
                "https://picsum.photos/seed/sm-product-{$seed}/600/600",
                "sm-product-{$seed}"
            );
        }
    }
}
