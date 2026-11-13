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
            ['slug' => 'shawarma', 'name_ar' => 'شاورما', 'name_en' => 'Shawarma'],
            ['slug' => 'burger', 'name_ar' => 'برغر', 'name_en' => 'Burgers'],
            ['slug' => 'pizza', 'name_ar' => 'بيتزا', 'name_en' => 'Pizza'],
            ['slug' => 'grill', 'name_ar' => 'مشاوي', 'name_en' => 'Grill'],
            ['slug' => 'fried-chicken', 'name_ar' => 'فروج ووجبات دجاج', 'name_en' => 'Chicken'],
            ['slug' => 'breakfast', 'name_ar' => 'فطور', 'name_en' => 'Breakfast'],
            ['slug' => 'pasta', 'name_ar' => 'باستا', 'name_en' => 'Pasta'],
            ['slug' => 'asian', 'name_ar' => 'آسيوي وسوشي', 'name_en' => 'Asian & Sushi'],
            ['slug' => 'sides', 'name_ar' => 'مقبلات وأطباق جانبية', 'name_en' => 'Sides'],
            ['slug' => 'desserts', 'name_ar' => 'حلويات', 'name_en' => 'Desserts'],
            ['slug' => 'drinks', 'name_ar' => 'مشروبات', 'name_en' => 'Drinks'],
        ];

        foreach ($categories as $index => $category) {
            FoodDiscoveryCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_active' => true, 'sort_order' => $index * 10],
            );
        }
    }
}
