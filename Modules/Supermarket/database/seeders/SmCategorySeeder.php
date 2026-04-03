<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Models\SmStore;

final class SmCategorySeeder extends Seeder
{
    public function run(): void
    {
        $storeMap = SmStore::query()
            ->whereIn('slug', [
                'supermarket-al-atrash',
                'supermarket-al-sultan',
                'supermarket-al-noor',
            ])
            ->get()
            ->keyBy('slug');

        $categories = [
            [
                'store' => 'supermarket-al-atrash',
                'items' => [
                    ['name' => 'مخبوزات', 'slug' => 'bakery', 'sort_order' => 1],
                    ['name' => 'معلبات', 'slug' => 'canned', 'sort_order' => 2],
                    ['name' => 'منظفات', 'slug' => 'cleaning', 'sort_order' => 3],
                    ['name' => 'ألبان', 'slug' => 'dairy', 'sort_order' => 4],
                    ['name' => 'تسالي', 'slug' => 'snacks', 'sort_order' => 5],
                ],
            ],
            [
                'store' => 'supermarket-al-sultan',
                'items' => [
                    ['name' => 'خضار', 'slug' => 'vegetables', 'sort_order' => 1],
                    ['name' => 'فواكه', 'slug' => 'fruits', 'sort_order' => 2],
                    ['name' => 'ألبان', 'slug' => 'dairy', 'sort_order' => 3],
                    ['name' => 'منظفات', 'slug' => 'cleaning', 'sort_order' => 4],
                    ['name' => 'أدوات منزلية', 'slug' => 'household', 'sort_order' => 5],
                ],
            ],
            [
                'store' => 'supermarket-al-noor',
                'items' => [
                    ['name' => 'معلبات', 'slug' => 'canned', 'sort_order' => 1],
                    ['name' => 'منظفات', 'slug' => 'cleaning', 'sort_order' => 2],
                    ['name' => 'ألبان', 'slug' => 'dairy', 'sort_order' => 3],
                    ['name' => 'تسالي', 'slug' => 'snacks', 'sort_order' => 4],
                ],
            ],
        ];

        foreach ($categories as $group) {
            $store = $storeMap->get($group['store']);
            if ($store === null) {
                continue;
            }

            foreach ($group['items'] as $item) {
                DB::table('sm_categories')->updateOrInsert(
                    [
                        'store_id' => $store->id,
                        'slug' => $item['slug'],
                    ],
                    [
                        'name' => $item['name'],
                        'description' => null,
                        'sort_order' => $item['sort_order'],
                        'image_path' => null,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
