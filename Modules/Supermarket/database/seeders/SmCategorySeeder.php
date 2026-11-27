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
        $catalog = SmDemoCatalog::load();
        $storeMap = SmStore::query()
            ->whereIn('slug', [
                'supermarket-al-atrash',
                'supermarket-al-sultan',
                'supermarket-al-noor',
            ])
            ->orWhere('slug', 'like', 'seller-supermarket-%')
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

        $sellerStores = $storeMap
            ->filter(static fn (SmStore $store): bool => str_starts_with($store->slug, 'seller-supermarket-'))
            ->values();

        foreach ($sellerStores as $store) {
            $categories[] = [
                'store' => $store->slug,
                'items' => [
                    ['name' => 'مواد غذائية أساسية', 'slug' => 'syrian-essentials', 'sort_order' => 1],
                    ['name' => 'ألبان وأجبان', 'slug' => 'syrian-dairy', 'sort_order' => 2],
                    ['name' => 'مشروبات', 'slug' => 'syrian-drinks', 'sort_order' => 3],
                    ['name' => 'حلويات وتسالي', 'slug' => 'syrian-snacks', 'sort_order' => 4],
                    ['name' => 'منظفات منزلية', 'slug' => 'syrian-cleaning', 'sort_order' => 5],
                ],
            ];
        }

        foreach ($categories as $group) {
            $store = $storeMap->get($group['store']);
            if ($store === null) {
                continue;
            }

            // The separately seeded demo seller is not created by SmStoreSeeder.
            if (str_starts_with($store->slug, 'seller-supermarket-')) {
                $store->fill([
                    'cover' => $store->cover ?: SmDemoCatalog::storeImage($store->slug, 'cover'),
                    'logo' => $store->logo ?: SmDemoCatalog::storeImage($store->slug, 'logo'),
                ])->save();
            }

            $items = collect($group['items'])->keyBy('slug');
            foreach ($catalog['categories'] as $slug => $category) {
                $slug = SmDemoCatalog::categorySlug($store->slug, $slug);
                if (! $items->has($slug)) {
                    $items->put($slug, [
                        'slug' => $slug,
                        'name' => $category['name'],
                        'sort_order' => $items->count() + 1,
                    ]);
                }
            }

            foreach ($items as $item) {
                $imageUrl = SmDemoCatalog::categoryImage($item['slug']);

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
