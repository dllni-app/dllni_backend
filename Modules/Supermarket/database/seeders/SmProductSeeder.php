<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Enums\SmProductSource;

final class SmProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['id' => 1, 'store_id' => 1, 'category_id' => 1, 'master_product_id' => 1, 'name' => 'حليب كامل الدسم 1 لتر', 'price' => 6, 'discounted_price' => null, 'stock_quantity' => 200, 'is_available' => true],
            ['id' => 2, 'store_id' => 1, 'category_id' => 2, 'master_product_id' => 4, 'name' => 'رز بسمتي 5 كغ', 'price' => 28, 'discounted_price' => 25, 'stock_quantity' => 100, 'is_available' => true],
            ['id' => 3, 'store_id' => 1, 'category_id' => 3, 'master_product_id' => 5, 'name' => 'دجاج طازج كامل', 'price' => 22, 'discounted_price' => null, 'stock_quantity' => 50, 'is_available' => true],
            ['id' => 4, 'store_id' => 1, 'category_id' => 4, 'master_product_id' => 6, 'name' => 'طماطم طازجة', 'price' => 4, 'discounted_price' => null, 'stock_quantity' => 150, 'is_available' => true],
            ['id' => 5, 'store_id' => 1, 'category_id' => 4, 'master_product_id' => 7, 'name' => 'خيار طازج', 'price' => 3, 'discounted_price' => null, 'stock_quantity' => 120, 'is_available' => true],
            ['id' => 6, 'store_id' => 1, 'category_id' => 5, 'master_product_id' => 10, 'name' => 'معكرونة سباغيتي 500غ', 'price' => 7, 'discounted_price' => null, 'stock_quantity' => 90, 'is_available' => true],
            ['id' => 7, 'store_id' => 1, 'category_id' => 1, 'master_product_id' => 8, 'name' => 'جبنة موزاريلا 200غ', 'price' => 12, 'discounted_price' => 10, 'stock_quantity' => 60, 'is_available' => true],
        ];

        foreach ($products as $product) {
            DB::table('sm_products')->updateOrInsert(
                ['id' => $product['id']],
                [
                    'store_id' => $product['store_id'],
                    'category_id' => $product['category_id'],
                    'master_product_id' => $product['master_product_id'],
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
    }
}
