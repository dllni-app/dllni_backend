<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SmOfferSeeder extends Seeder
{
    public function run(): void
    {
        $offers = [
            ['id' => 1, 'store_id' => 1, 'name' => 'عرض الألبان الأسبوعي', 'is_active' => true],
            ['id' => 2, 'store_id' => 1, 'name' => 'خصم 15% على المعكرونة', 'is_active' => true],
        ];

        foreach ($offers as $offer) {
            DB::table('sm_offers')->updateOrInsert(
                ['id' => $offer['id']],
                [
                    'store_id' => $offer['store_id'],
                    'name' => $offer['name'],
                    'description' => null,
                    'offer_type' => 'percentage',
                    'discount_value' => null,
                    'discount_percent' => 15,
                    'starts_at' => null,
                    'ends_at' => null,
                    'is_active' => $offer['is_active'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
