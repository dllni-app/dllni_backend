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
                'name' => 'داخل الثلاجة',
                'slug' => 'inside-fridge',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 25.00,
                'is_active' => true,
            ],
            [
                'name' => 'داخل الفرن',
                'slug' => 'inside-oven',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 20.00,
                'is_active' => true,
            ],
            [
                'name' => 'تنظيف النوافذ',
                'slug' => 'window-cleaning',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 15.00,
                'is_active' => true,
            ],
            [
                'name' => 'غسيل وكي',
                'slug' => 'laundry-ironing',
                'pricing_type' => AddonPricingType::Fixed->value,
                'price_value' => 35.00,
                'is_active' => true,
            ],
            [
                'name' => 'تنظيف عميق إضافي',
                'slug' => 'deep-clean-extra',
                'pricing_type' => AddonPricingType::Percentage->value,
                'price_value' => 25.00,
                'is_active' => true,
            ],
            [
                'name' => 'داخل الخزائن',
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
