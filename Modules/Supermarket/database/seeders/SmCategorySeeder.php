<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Supermarket\Models\SmCategory;
use Modules\Supermarket\Models\SmStore;

final class SmCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categoriesData = [
            ['name' => 'خضروات وفواكه', 'slug' => 'vegetables-fruits', 'description' => 'خضروات وفواكه طازجة يومياً', 'sort_order' => 1],
            ['name' => 'ألبان وأجبان', 'slug' => 'dairy-cheese', 'description' => 'حليب، لبن، أجبان، وزبادي', 'sort_order' => 2],
            ['name' => 'لحوم ودواجن', 'slug' => 'meat-poultry', 'description' => 'لحوم حمراء، دجاج، وسمك طازج', 'sort_order' => 3],
            ['name' => 'مخبوزات وحلويات', 'slug' => 'bakery-sweets', 'description' => 'خبز، معجنات، وحلويات', 'sort_order' => 4],
            ['name' => 'معلبات ومجمدات', 'slug' => 'canned-frozen', 'description' => 'معلبات، مجمدات، وأطعمة جاهزة', 'sort_order' => 5],
            ['name' => 'مشروبات', 'slug' => 'beverages', 'description' => 'عصائر، مشروبات غازية، ومياه', 'sort_order' => 6],
            ['name' => 'تنظيف ومنزل', 'slug' => 'cleaning-household', 'description' => 'منظفات ومستلزمات منزلية', 'sort_order' => 7],
        ];

        SmStore::each(function (SmStore $store) use ($categoriesData): void {
            foreach ($categoriesData as $data) {
                SmCategory::firstOrCreate(
                    [
                        'store_id' => $store->id,
                        'slug' => $data['slug'],
                    ],
                    [
                        'name' => $data['name'],
                        'description' => $data['description'],
                        'sort_order' => $data['sort_order'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
