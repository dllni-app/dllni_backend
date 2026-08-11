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
                'cost_per_km' => 10,
                'fixed_fee' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'المنطقة الممتدة',
                'max_km' => 25,
                'cost_per_km' => 10,
                'fixed_fee' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'المنطقة الخارجية',
                'max_km' => 50,
                'cost_per_km' => 10,
                'fixed_fee' => 25,
                'is_active' => true,
            ],
        ];

        foreach ($configs as $config) {
            $query = DB::table('travel_cost_configs')->where('name', $config['name']);

            if ($query->exists()) {
                $query->update(array_merge($config, [
                    'updated_at' => now(),
                ]));

                continue;
            }

            DB::table('travel_cost_configs')->insert(array_merge($config, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
