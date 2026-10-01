<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\Recipe;
use App\Models\RecipeAlias;
use App\Models\RecipeIngredient;
use Illuminate\Database\Seeder;

final class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $recipes = [
            ['id' => 1, 'name' => 'بيتزا مارجريتا', 'slug' => 'pizza-margherita', 'description' => 'بيتزا كلاسيكية بالجبنة والطماطم', 'servings' => 4, 'is_active' => true],
            ['id' => 2, 'name' => 'كبسة دجاج', 'slug' => 'kabsa-chicken', 'description' => 'طبق أرز مع دجاج وتوابل', 'servings' => 5, 'is_active' => true],
            ['id' => 3, 'name' => 'سلطة خضار', 'slug' => 'vegetable-salad', 'description' => 'سلطة طازجة صحية', 'servings' => 2, 'is_active' => true],
            ['id' => 4, 'name' => 'معكرونة بالصلصة', 'slug' => 'pasta-red-sauce', 'description' => 'معكرونة بصلصة الطماطم', 'servings' => 3, 'is_active' => true],
            ['id' => 5, 'name' => 'لازانيا باللحمة', 'slug' => 'lasagna-beef', 'description' => 'لازانيا منزلية باللحمة والجبنة وصلصة الطماطم', 'servings' => 6, 'is_active' => true],
        ];

        foreach ($recipes as $recipeData) {
            Recipe::updateOrCreate(
                ['id' => $recipeData['id']],
                $recipeData
            );
        }

        $ingredients = [
            ['id' => 1, 'recipe_id' => 1, 'master_product_id' => 8, 'quantity' => 300, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 2, 'recipe_id' => 1, 'master_product_id' => 6, 'quantity' => 200, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 3, 'recipe_id' => 1, 'master_product_id' => 9, 'quantity' => 50, 'unit' => MasterProductUnit::Milliliter, 'is_optional' => false],
            ['id' => 4, 'recipe_id' => 2, 'master_product_id' => 4, 'quantity' => 1, 'unit' => MasterProductUnit::Kilogram, 'is_optional' => false],
            ['id' => 5, 'recipe_id' => 2, 'master_product_id' => 5, 'quantity' => 1, 'unit' => MasterProductUnit::Kilogram, 'is_optional' => false],
            ['id' => 6, 'recipe_id' => 3, 'master_product_id' => 6, 'quantity' => 300, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 7, 'recipe_id' => 3, 'master_product_id' => 7, 'quantity' => 200, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 8, 'recipe_id' => 4, 'master_product_id' => 10, 'quantity' => 500, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 9, 'recipe_id' => 4, 'master_product_id' => 6, 'quantity' => 250, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 10, 'recipe_id' => 5, 'master_product_id' => 23, 'quantity' => 500, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 11, 'recipe_id' => 5, 'master_product_id' => 24, 'quantity' => 0.75, 'unit' => MasterProductUnit::Kilogram, 'is_optional' => false],
            ['id' => 12, 'recipe_id' => 5, 'master_product_id' => 25, 'quantity' => 680, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 13, 'recipe_id' => 5, 'master_product_id' => 26, 'quantity' => 0.25, 'unit' => MasterProductUnit::Kilogram, 'is_optional' => false],
            ['id' => 14, 'recipe_id' => 5, 'master_product_id' => 8, 'quantity' => 400, 'unit' => MasterProductUnit::Gram, 'is_optional' => false],
            ['id' => 15, 'recipe_id' => 5, 'master_product_id' => 1, 'quantity' => 1, 'unit' => MasterProductUnit::Liter, 'is_optional' => false],
            ['id' => 16, 'recipe_id' => 5, 'master_product_id' => 22, 'quantity' => 100, 'unit' => MasterProductUnit::Gram, 'is_optional' => true],
            ['id' => 17, 'recipe_id' => 5, 'master_product_id' => 27, 'quantity' => 100, 'unit' => MasterProductUnit::Gram, 'is_optional' => true],
        ];

        $aliases = [
            1 => ['بيتزا مارجريتا', 'مارغريتا', 'margherita pizza'],
            2 => ['كبسة', 'كبسة دجاج', 'chicken kabsa'],
            3 => ['سلطة', 'سلطة خضار'],
            4 => ['معكرونة بالصلصة', 'باستا بالصلصة'],
            5 => ['لازانيا', 'لزانية', 'لازانيا باللحمة', 'lasagna'],
        ];

        foreach ($aliases as $recipeId => $names) {
            foreach ($names as $alias) {
                RecipeAlias::firstOrCreate([
                    'recipe_id' => $recipeId,
                    'alias' => $alias,
                ]);
            }
        }

        foreach ($ingredients as $ingredient) {
            RecipeIngredient::updateOrCreate(
                ['id' => $ingredient['id']],
                [
                    'recipe_id' => $ingredient['recipe_id'],
                    'master_product_id' => $ingredient['master_product_id'],
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit']->value,
                    'is_optional' => $ingredient['is_optional'],
                ]
            );
        }
    }
}
