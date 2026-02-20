<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class TravelCostConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [
                'name' => 'المنطقة المحلية',
                'max_km' => 10,
                'cost_per_km' => 0.50,
                'fixed_fee' => 5.00,
                'is_active' => true,
            ],
            [
                'name' => 'المنطقة الممتدة',
                'max_km' => 25,
                'cost_per_km' => 0.75,
                'fixed_fee' => 10.00,
                'is_active' => true,
            ],
            [
                'name' => 'المنطقة الخارجية',
                'max_km' => 50,
                'cost_per_km' => 1.00,
                'fixed_fee' => 15.00,
                'is_active' => true,
            ],
        ];

        foreach ($configs as $config) {
            $exists = DB::table('travel_cost_configs')->where('name', $config['name'])->exists();
            if (! $exists) {
                DB::table('travel_cost_configs')->insert(array_merge($config, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }
}
