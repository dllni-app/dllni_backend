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
            [
                'name' => 'Tomatoes',
                'barcode' => '5901234123457',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'Fresh Farm',
                'description' => 'Fresh vine tomatoes',
                'aliases' => ['cherry tomatoes', 'roma tomatoes'],
            ],
            [
                'name' => 'Olive Oil',
                'barcode' => '5901234123458',
                'unit' => MasterProductUnit::Liter,
                'brand' => 'Mediterranean Gold',
                'description' => 'Extra virgin olive oil',
                'aliases' => ['evoo', 'olive oil extra virgin'],
            ],
            [
                'name' => 'Chicken Breast',
                'barcode' => '5901234123459',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'Farm Fresh',
                'description' => 'Skinless chicken breast',
                'aliases' => ['chicken', 'white meat'],
            ],
            [
                'name' => 'Rice',
                'barcode' => '5901234123460',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'Golden Grain',
                'description' => 'Long grain basmati rice',
                'aliases' => ['basmati', 'white rice'],
            ],
            [
                'name' => 'All-Purpose Flour',
                'barcode' => '5901234123461',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'Baker\'s Choice',
                'description' => 'Wheat flour for baking',
                'aliases' => ['flour', 'plain flour'],
            ],
            [
                'name' => 'Milk',
                'barcode' => '5901234123462',
                'unit' => MasterProductUnit::Liter,
                'brand' => 'Dairy Fresh',
                'description' => 'Full fat milk',
                'aliases' => ['whole milk', 'fresh milk'],
            ],
            [
                'name' => 'Eggs',
                'barcode' => '5901234123463',
                'unit' => MasterProductUnit::Pack,
                'brand' => 'Farm Eggs',
                'description' => 'Free range eggs, 12 pack',
                'aliases' => ['eggs', 'dozen eggs'],
            ],
            [
                'name' => 'Onions',
                'barcode' => '5901234123464',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => null,
                'description' => 'Yellow onions',
                'aliases' => ['yellow onion', 'cooking onions'],
            ],
            [
                'name' => 'Garlic',
                'barcode' => '5901234123465',
                'unit' => MasterProductUnit::Piece,
                'brand' => null,
                'description' => 'Fresh garlic bulb',
                'aliases' => ['garlic bulb', 'fresh garlic'],
            ],
            [
                'name' => 'Lemon',
                'barcode' => '5901234123466',
                'unit' => MasterProductUnit::Piece,
                'brand' => null,
                'description' => 'Fresh lemons',
                'aliases' => ['lemons', 'citrus'],
            ],
        ];

        foreach ($products as $data) {
            $aliases = $data['aliases'] ?? [];
            unset($data['aliases']);

            $product = MasterProduct::firstOrCreate(
                ['barcode' => $data['barcode']],
                [
                    'name' => $data['name'],
                    'unit' => $data['unit']->value,
                    'brand' => $data['brand'],
                    'description' => $data['description'],
                    'is_active' => true,
                ]
            );

            foreach ($aliases as $alias) {
                MasterProductAlias::firstOrCreate(
                    [
                        'master_product_id' => $product->id,
                        'alias' => $alias,
                    ]
                );
            }
        }
    }
}
