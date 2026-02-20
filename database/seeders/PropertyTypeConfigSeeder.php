<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LivingRoomSize;
use App\Enums\PropertyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PropertyTypeConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [
                'property_type' => PropertyType::Studio->value,
                'living_room_size' => null,
                'base_sqm_min' => 25,
                'base_sqm_max' => 45,
                'base_hours' => 2,
                'rules' => ['min_rooms' => 1],
            ],
            [
                'property_type' => PropertyType::Apartment->value,
                'living_room_size' => LivingRoomSize::Small->value,
                'base_sqm_min' => 50,
                'base_sqm_max' => 75,
                'base_hours' => 3,
                'rules' => ['min_rooms' => 2],
            ],
            [
                'property_type' => PropertyType::Apartment->value,
                'living_room_size' => LivingRoomSize::Medium->value,
                'base_sqm_min' => 76,
                'base_sqm_max' => 100,
                'base_hours' => 4,
                'rules' => ['min_rooms' => 3],
            ],
            [
                'property_type' => PropertyType::Apartment->value,
                'living_room_size' => LivingRoomSize::Large->value,
                'base_sqm_min' => 101,
                'base_sqm_max' => 150,
                'base_hours' => 5,
                'rules' => ['min_rooms' => 4],
            ],
            [
                'property_type' => PropertyType::Villa->value,
                'living_room_size' => null,
                'base_sqm_min' => 200,
                'base_sqm_max' => 400,
                'base_hours' => 8,
                'rules' => ['min_rooms' => 1],
            ],
            [
                'property_type' => PropertyType::Office->value,
                'living_room_size' => null,
                'base_sqm_min' => 50,
                'base_sqm_max' => 200,
                'base_hours' => 4,
                'rules' => null,
            ],
        ];

        foreach ($configs as $config) {
            $exists = DB::table('property_type_configs')
                ->where('property_type', $config['property_type'])
                ->where('living_room_size', $config['living_room_size'])
                ->exists();

            if (! $exists) {
                $row = array_merge($config, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (isset($row['rules']) && is_array($row['rules'])) {
                    $row['rules'] = json_encode($row['rules']);
                }
                DB::table('property_type_configs')->insert($row);
            }
        }
    }
}
