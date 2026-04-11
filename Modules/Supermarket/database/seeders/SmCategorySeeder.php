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
                $imageUrl = match ($item['slug']) {
                    'bakery' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=1200&q=80',
                    'canned' => 'https://images.unsplash.com/photo-1584263347416-85a696b4eda7?auto=format&fit=crop&w=1200&q=80',
                    'cleaning' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952?auto=format&fit=crop&w=1200&q=80',
                    'dairy' => 'https://images.unsplash.com/photo-1550583724-b2692b85b150?auto=format&fit=crop&w=1200&q=80',
                    'snacks' => 'https://images.unsplash.com/photo-1599490659213-e2b9527bd087?auto=format&fit=crop&w=1200&q=80',
                    'vegetables' => 'https://images.unsplash.com/photo-1518843875459-f738682238a6?auto=format&fit=crop&w=1200&q=80',
                    'fruits' => 'https://images.unsplash.com/photo-1610832958506-aa56368176cf?auto=format&fit=crop&w=1200&q=80',
                    'household' => 'https://images.unsplash.com/photo-1583947215259-38e31be8751f?auto=format&fit=crop&w=1200&q=80',
                    default => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1200&q=80',
                };

                DB::table('sm_categories')->updateOrInsert(
                    [
                        'store_id' => $store->id,
                        'slug' => $item['slug'],
                    ],
                    [
                        'name' => $item['name'],
                        'description' => null,
                        'sort_order' => $item['sort_order'],
                        'image_path' => $imageUrl,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
