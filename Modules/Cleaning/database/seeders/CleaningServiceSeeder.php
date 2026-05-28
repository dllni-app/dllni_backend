<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Enums\LivingRoomSize;
use App\Enums\PropertyType;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\ServicePricing;

final class CleaningServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            [
                'name' => 'تنظيف الشقة المعياري',
                'slug' => 'standard-apartment-cleaning',
                'category' => ServiceCategory::Cleaning->value,
                'description' => 'تنظيف دوري للشقق يشمل المسح والكنس والغسيل وتنظيف الحمام والمطبخ.',
                'price' => 45.00,
                'pricing' => [
                    [PropertyType::Studio->value, null, 45.00, 0.50, 2],
                    [PropertyType::Apartment->value, LivingRoomSize::Small->value, 55.00, 0.45, 3],
                    [PropertyType::Apartment->value, LivingRoomSize::Medium->value, 70.00, 0.45, 4],
                    [PropertyType::Apartment->value, LivingRoomSize::Large->value, 90.00, 0.45, 5],
                    [PropertyType::Villa->value, null, 150.00, 0.40, 8],
                ],
            ],
            [
                'name' => 'تنظيف عميق',
                'slug' => 'deep-cleaning',
                'category' => ServiceCategory::Cleaning->value,
                'description' => 'تنظيف شامل يشمل داخل الخزائن والأجهزة والمناطق صعبة الوصول.',
                'price' => 85.00,
                'pricing' => [
                    [PropertyType::Studio->value, null, 85.00, 1.00, 3],
                    [PropertyType::Apartment->value, LivingRoomSize::Small->value, 110.00, 0.90, 4],
                    [PropertyType::Apartment->value, LivingRoomSize::Medium->value, 140.00, 0.90, 5],
                    [PropertyType::Apartment->value, LivingRoomSize::Large->value, 180.00, 0.90, 6],
                    [PropertyType::Villa->value, null, 280.00, 0.85, 10],
                ],
            ],
            [
                'name' => 'تنظيف نقل السكن',
                'slug' => 'move-in-move-out-cleaning',
                'category' => ServiceCategory::Cleaning->value,
                'description' => 'تنظيف شامل عند الانتقال يضمن جاهزية العقار للمستأجرين الجدد.',
                'price' => 95.00,
                'pricing' => [
                    [PropertyType::Studio->value, null, 95.00, 1.20, 3],
                    [PropertyType::Apartment->value, LivingRoomSize::Small->value, 125.00, 1.10, 4],
                    [PropertyType::Apartment->value, LivingRoomSize::Medium->value, 160.00, 1.10, 5],
                    [PropertyType::Apartment->value, LivingRoomSize::Large->value, 200.00, 1.10, 6],
                ],
            ],
            [
                'name' => 'مساعدة في المناسبات',
                'slug' => 'event-assistance',
                'category' => ServiceCategory::EventAssistance->value,
                'description' => 'مساعدة في التحضير والتقديم والتنظيف للمناسبات والتجمعات.',
                'price' => 25.00,
                'pricing' => [
                    [PropertyType::Apartment->value, null, 25.00, null, 4],
                    [PropertyType::Villa->value, null, 25.00, null, 6],
                ],
            ],
            [
                'name' => 'تنظيف المكاتب',
                'slug' => 'office-cleaning',
                'category' => ServiceCategory::Cleaning->value,
                'description' => 'تنظيف مكاتب احترافي يشمل المكاتب والمناطق المشتركة ودورات المياه.',
                'price' => 60.00,
                'pricing' => [
                    [PropertyType::Office->value, null, 60.00, 0.35, 4],
                ],
            ],
        ];

        foreach ($services as $data) {
            $pricing = $data['pricing'];
            unset($data['pricing']);

            $service = CleaningService::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'category' => $data['category'],
                    'description' => $data['description'],
                    'price' => $data['price'],
                    'is_active' => true,
                ]
            );

            foreach ($pricing as $p) {
                [$propertyType, $livingRoomSize, $basePrice, $pricePerSqm, $minHours] = $p;

                ServicePricing::updateOrCreate(
                    [
                        'cleaning_service_id' => $service->id,
                        'property_type' => $propertyType,
                        'living_room_size' => $livingRoomSize,
                    ],
                    [
                        'base_price' => $basePrice,
                        'price_per_sqm' => $pricePerSqm,
                        'min_hours' => $minHours,
                    ]
                );
            }
        }
    }
}
