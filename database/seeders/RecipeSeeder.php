<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Database\Seeder;

final class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $recipes = [
            [
                'name' => 'Chicken Stir Fry',
                'slug' => 'chicken-stir-fry',
                'description' => 'Quick and easy chicken stir fry with vegetables',
                'servings' => 4,
                'ingredients' => [
                    ['name' => 'Chicken Breast', 'quantity' => 0.5, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'Rice', 'quantity' => 0.3, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'Olive Oil', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'Onions', 'quantity' => 0.2, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'Garlic', 'quantity' => 2, 'unit' => MasterProductUnit::Piece],
                ],
            ],
            [
                'name' => 'Tomato Pasta',
                'slug' => 'tomato-pasta',
                'description' => 'Classic tomato pasta with fresh basil',
                'servings' => 2,
                'ingredients' => [
                    ['name' => 'Tomatoes', 'quantity' => 0.5, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'Olive Oil', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'Garlic', 'quantity' => 3, 'unit' => MasterProductUnit::Piece],
                    ['name' => 'All-Purpose Flour', 'quantity' => 0.2, 'unit' => MasterProductUnit::Kilogram],
                ],
            ],
            [
                'name' => 'Scrambled Eggs',
                'slug' => 'scrambled-eggs',
                'description' => 'Fluffy scrambled eggs with milk',
                'servings' => 2,
                'ingredients' => [
                    ['name' => 'Eggs', 'quantity' => 1, 'unit' => MasterProductUnit::Pack],
                    ['name' => 'Milk', 'quantity' => 0.05, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'Olive Oil', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                ],
            ],
            [
                'name' => 'Lemon Chicken',
                'slug' => 'lemon-chicken',
                'description' => 'Baked chicken with lemon and herbs',
                'servings' => 4,
                'ingredients' => [
                    ['name' => 'Chicken Breast', 'quantity' => 0.6, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'Lemon', 'quantity' => 3, 'unit' => MasterProductUnit::Piece],
                    ['name' => 'Olive Oil', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'Garlic', 'quantity' => 2, 'unit' => MasterProductUnit::Piece],
                ],
            ],
        ];

        foreach ($recipes as $data) {
            $ingredients = $data['ingredients'];
            unset($data['ingredients']);

            $recipe = Recipe::firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'servings' => $data['servings'],
                    'is_active' => true,
                ]
            );

            foreach ($ingredients as $ing) {
                $product = MasterProduct::where('name', $ing['name'])->first();
                if ($product && ! RecipeIngredient::where('recipe_id', $recipe->id)->where('master_product_id', $product->id)->exists()) {
                    RecipeIngredient::create([
                        'recipe_id' => $recipe->id,
                        'master_product_id' => $product->id,
                        'quantity' => $ing['quantity'],
                        'unit' => $ing['unit']->value,
                        'is_optional' => false,
                    ]);
                }
            }
        }
    }
}
