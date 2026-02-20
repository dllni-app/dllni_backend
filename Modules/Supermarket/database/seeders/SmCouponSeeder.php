<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmStore;

final class SmCouponSeeder extends Seeder
{
    public function run(): void
    {
        $couponsData = [
            [
                'code' => 'ترحيب10',
                'name_ar' => 'كوبون ترحيب',
                'type' => 'percent',
                'value' => null,
                'percent' => 10,
                'min_order_amount' => 20.00,
                'max_discount_amount' => 15.00,
            ],
            [
                'code' => 'عرض25',
                'name_ar' => 'خصم 25 دينار',
                'type' => 'fixed',
                'value' => 25.00,
                'percent' => null,
                'min_order_amount' => 50.00,
                'max_discount_amount' => null,
            ],
            [
                'code' => 'صيف5',
                'name_ar' => 'خصم الصيف',
                'type' => 'percent',
                'value' => null,
                'percent' => 5,
                'min_order_amount' => 15.00,
                'max_discount_amount' => 10.00,
            ],
        ];

        SmStore::each(function (SmStore $store) use ($couponsData): void {
            foreach ($couponsData as $i => $data) {
                $code = $data['code'].'-'.mb_substr((string) $store->id, -2);
                SmCoupon::firstOrCreate(
                    ['store_id' => $store->id, 'code' => $code],
                    [
                        'type' => $data['type'],
                        'value' => $data['value'],
                        'percent' => $data['percent'],
                        'min_order_amount' => $data['min_order_amount'],
                        'max_discount_amount' => $data['max_discount_amount'],
                        'usage_limit' => 100,
                        'used_count' => 0,
                        'starts_at' => now(),
                        'ends_at' => now()->addMonths(3),
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
