<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Models\SmStore;

final class SmOfferSeeder extends Seeder
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

        $offers = [
            [
                'store' => 'supermarket-al-atrash',
                'name' => 'عروض استثنائية',
                'description' => 'خصم 30% على جميع المنتجات لفترة محدودة.',
                'offer_type' => 'percentage',
                'discount_percent' => 30,
                'discount_value' => null,
            ],
            [
                'store' => 'supermarket-al-sultan',
                'name' => 'خصم 20%',
                'description' => 'خصم 20% على منتجات مختارة.',
                'offer_type' => 'percentage',
                'discount_percent' => 20,
                'discount_value' => null,
            ],
            [
                'store' => 'supermarket-al-noor',
                'name' => 'خصم 15%',
                'description' => 'خصم 15% على السلع الأساسية.',
                'offer_type' => 'percentage',
                'discount_percent' => 15,
                'discount_value' => null,
            ],
        ];

        foreach ($offers as $offer) {
            $store = $storeMap->get($offer['store']);
            if ($store === null) {
                continue;
            }

            DB::table('sm_offers')->updateOrInsert(
                [
                    'store_id' => $store->id,
                    'name' => $offer['name'],
                ],
                [
                    'description' => $offer['description'],
                    'offer_type' => $offer['offer_type'],
                    'discount_value' => $offer['discount_value'],
                    'discount_percent' => $offer['discount_percent'],
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addDays(10),
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
