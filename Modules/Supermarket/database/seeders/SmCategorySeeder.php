<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SmCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['id' => 1, 'store_id' => 1, 'name' => 'الألبان', 'slug' => 'dairy', 'sort_order' => 1],
            ['id' => 2, 'store_id' => 1, 'name' => 'الحبوب والأرز', 'slug' => 'grains', 'sort_order' => 2],
            ['id' => 3, 'store_id' => 1, 'name' => 'اللحوم والدواجن', 'slug' => 'meat', 'sort_order' => 3],
            ['id' => 4, 'store_id' => 1, 'name' => 'الخضار والفواكه', 'slug' => 'vegetables', 'sort_order' => 4],
            ['id' => 5, 'store_id' => 1, 'name' => 'المعكرونة', 'slug' => 'pasta', 'sort_order' => 5],
        ];

        foreach ($categories as $category) {
            DB::table('sm_categories')->updateOrInsert(
                ['id' => $category['id']],
                [
                    'store_id' => $category['store_id'],
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'description' => null,
                    'sort_order' => $category['sort_order'],
                    'image_path' => null,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
