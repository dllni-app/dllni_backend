<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ServiceAddon;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\AddonPricingType;

final class ServiceAddonSeeder extends Seeder
{
    public function run(): void
    {
        $addons = [
            [
                'name' => 'Inside Fridge',
                'slug' => 'inside-fridge',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 25.00,
                'is_active' => true,
            ],
            [
                'name' => 'Inside Oven',
                'slug' => 'inside-oven',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 20.00,
                'is_active' => true,
            ],
            [
                'name' => 'Window Cleaning',
                'slug' => 'window-cleaning',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 15.00,
                'is_active' => true,
            ],
            [
                'name' => 'Laundry & Ironing',
                'slug' => 'laundry-ironing',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 35.00,
                'is_active' => true,
            ],
            [
                'name' => 'Deep Clean Extra',
                'slug' => 'deep-clean-extra',
                'pricing_type' => AddonPricingType::Percentage->value,
                'price_value' => 25.00,
                'is_active' => true,
            ],
            [
                'name' => 'Inside Cabinets',
                'slug' => 'inside-cabinets',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 30.00,
                'is_active' => true,
            ],
        ];

        foreach ($addons as $addon) {
            ServiceAddon::firstOrCreate(
                ['slug' => $addon['slug']],
                $addon
            );
        }
    }
}
