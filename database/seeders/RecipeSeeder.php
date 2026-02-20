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
                'name' => 'دجاج بالخضار',
                'slug' => 'chicken-stir-fry',
                'description' => 'دجاج سريع وسهل مع الخضار',
                'servings' => 4,
                'ingredients' => [
                    ['name' => 'صدر دجاج', 'quantity' => 0.5, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'أرز', 'quantity' => 0.3, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'زيت زيتون', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'بصل', 'quantity' => 0.2, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'ثوم', 'quantity' => 2, 'unit' => MasterProductUnit::Piece],
                ],
            ],
            [
                'name' => 'باستا بالطماطم',
                'slug' => 'tomato-pasta',
                'description' => 'باستا كلاسيكية بالطماطم والريحان الطازج',
                'servings' => 2,
                'ingredients' => [
                    ['name' => 'طماطم', 'quantity' => 0.5, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'زيت زيتون', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'ثوم', 'quantity' => 3, 'unit' => MasterProductUnit::Piece],
                    ['name' => 'دقيق متعدد الاستخدام', 'quantity' => 0.2, 'unit' => MasterProductUnit::Kilogram],
                ],
            ],
            [
                'name' => 'بيض مخفوق',
                'slug' => 'scrambled-eggs',
                'description' => 'بيض مخفوق رقيق مع الحليب',
                'servings' => 2,
                'ingredients' => [
                    ['name' => 'بيض', 'quantity' => 1, 'unit' => MasterProductUnit::Pack],
                    ['name' => 'حليب', 'quantity' => 0.05, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'زيت زيتون', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                ],
            ],
            [
                'name' => 'دجاج بالليمون',
                'slug' => 'lemon-chicken',
                'description' => 'دجاج بالفرن مع الليمون والأعشاب',
                'servings' => 4,
                'ingredients' => [
                    ['name' => 'صدر دجاج', 'quantity' => 0.6, 'unit' => MasterProductUnit::Kilogram],
                    ['name' => 'ليمون', 'quantity' => 3, 'unit' => MasterProductUnit::Piece],
                    ['name' => 'زيت زيتون', 'quantity' => 0.5, 'unit' => MasterProductUnit::Liter],
                    ['name' => 'ثوم', 'quantity' => 2, 'unit' => MasterProductUnit::Piece],
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
