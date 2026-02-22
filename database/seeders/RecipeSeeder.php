<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\Recipe;
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
        ];

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
