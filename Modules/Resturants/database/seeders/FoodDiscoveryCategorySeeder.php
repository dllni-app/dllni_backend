<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Resturants\Models\FoodDiscoveryCategory;

final class FoodDiscoveryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'shawarma', 'name_ar' => 'شاورما', 'name_en' => 'Shawarma', 'aliases' => ['شاورمه', 'shawarma']],
            ['slug' => 'fried-chicken', 'name_ar' => 'دجاج مقرمش', 'name_en' => 'Fried chicken', 'aliases' => ['بروستد', 'بروست', 'كريسبي', 'fried chicken']],
            ['slug' => 'burger', 'name_ar' => 'برغر', 'name_en' => 'Burgers', 'aliases' => ['برجر', 'burger']],
            ['slug' => 'pizza', 'name_ar' => 'بيتزا', 'name_en' => 'Pizza', 'aliases' => ['بيتزا', 'pizza']],
            ['slug' => 'grill', 'name_ar' => 'مشاوي', 'name_en' => 'Grill', 'aliases' => ['مشويات', 'كباب', 'grill']],
            ['slug' => 'falafel', 'name_ar' => 'فلافل', 'name_en' => 'Falafel', 'aliases' => ['فلافل', 'falafel']],
            ['slug' => 'pasta', 'name_ar' => 'معكرونة', 'name_en' => 'Pasta', 'aliases' => ['مكرونة', 'باستا', 'pasta']],
            ['slug' => 'potato', 'name_ar' => 'بطاطا', 'name_en' => 'Potato', 'aliases' => ['بطاطس', 'فرنش فرايز', 'fries', 'potato']],
            ['slug' => 'salads', 'name_ar' => 'سلطات', 'name_en' => 'Salads', 'aliases' => ['سلطة', 'salad']],
            ['slug' => 'pastries', 'name_ar' => 'معجنات', 'name_en' => 'Pastries', 'aliases' => ['معجنات', 'فطائر', 'pastries']],
            ['slug' => 'desserts', 'name_ar' => 'حلويات', 'name_en' => 'Desserts', 'aliases' => ['حلوى', 'dessert']],
            ['slug' => 'drinks', 'name_ar' => 'مشروبات', 'name_en' => 'Drinks', 'aliases' => ['مشروب', 'عصير', 'drink']],
            ['slug' => 'breakfast', 'name_ar' => 'فطور', 'name_en' => 'Breakfast'],
            ['slug' => 'asian', 'name_ar' => 'آسيوي وسوشي', 'name_en' => 'Asian & Sushi'],
            ['slug' => 'sides', 'name_ar' => 'مقبلات وأطباق جانبية', 'name_en' => 'Sides'],
        ];

        foreach ($categories as $index => $category) {
            FoodDiscoveryCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_active' => true, 'sort_order' => $index * 10],
            );
        }
    }
}
