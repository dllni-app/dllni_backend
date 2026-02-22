<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use App\Models\MasterProductAlias;
use Illuminate\Database\Seeder;

final class MasterProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['id' => 1, 'name' => 'حليب كامل الدسم', 'barcode' => '6281001000011', 'unit' => MasterProductUnit::Liter, 'brand' => 'المراعي', 'description' => 'حليب بقري كامل الدسم 1 لتر', 'is_active' => true, 'aliases' => ['حليب', 'لبن حليب']],
            ['id' => 2, 'name' => 'حليب قليل الدسم', 'barcode' => '6281001000012', 'unit' => MasterProductUnit::Liter, 'brand' => 'المراعي', 'description' => 'حليب قليل الدسم 1 لتر', 'is_active' => true, 'aliases' => []],
            ['id' => 3, 'name' => 'سكر أبيض', 'barcode' => '6282002000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'الأسرة', 'description' => 'سكر أبيض ناعم 1 كغ', 'is_active' => true, 'aliases' => ['سكر']],
            ['id' => 4, 'name' => 'رز بسمتي', 'barcode' => '6283003000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'أبو كاس', 'description' => 'رز بسمتي فاخر', 'is_active' => true, 'aliases' => ['رز']],
            ['id' => 5, 'name' => 'دجاج طازج', 'barcode' => '6284004000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'الوطنية', 'description' => 'دجاج طازج كامل', 'is_active' => true, 'aliases' => ['فروج', 'دجاجة']],
            ['id' => 6, 'name' => 'طماطم', 'barcode' => '0000000001001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'محلي', 'description' => 'طماطم طازجة', 'is_active' => true, 'aliases' => ['بندورة']],
            ['id' => 7, 'name' => 'خيار', 'barcode' => '0000000001002', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'محلي', 'description' => 'خيار طازج', 'is_active' => true, 'aliases' => []],
            ['id' => 8, 'name' => 'جبنة موزاريلا', 'barcode' => '6285005000001', 'unit' => MasterProductUnit::Gram, 'brand' => 'برايد', 'description' => 'جبنة موزاريلا مبشورة', 'is_active' => true, 'aliases' => ['موزريلا']],
            ['id' => 9, 'name' => 'زيت زيتون', 'barcode' => '6286006000001', 'unit' => MasterProductUnit::Liter, 'brand' => 'الجوف', 'description' => 'زيت زيتون بكر ممتاز', 'is_active' => true, 'aliases' => ['زيت']],
            ['id' => 10, 'name' => 'معكرونة سباغيتي', 'barcode' => '6287007000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'العلالي', 'description' => 'معكرونة سباغيتي 500غ', 'is_active' => true, 'aliases' => ['باستا']],
        ];

        foreach ($products as $data) {
            $aliases = $data['aliases'];
            unset($data['aliases']);

            $product = MasterProduct::updateOrCreate(
                ['id' => $data['id']],
                [
                    'name' => $data['name'],
                    'barcode' => $data['barcode'],
                    'unit' => $data['unit']->value,
                    'brand' => $data['brand'],
                    'description' => $data['description'],
                    'is_active' => $data['is_active'],
                ]
            );

            foreach ($aliases as $alias) {
                MasterProductAlias::firstOrCreate(
                    ['master_product_id' => $product->id, 'alias' => $alias]
                );
            }
        }
    }
}
