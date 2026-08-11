<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Cleaning\Models\CleaningHomeType;

final class CleaningHomeTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->items() as $item) {
            $type = CleaningHomeType::query()
                ->withTrashed()
                ->updateOrCreate(
                    [
                        'section' => $item['section'],
                        'code' => $item['code'],
                    ],
                    [
                        'booking_value' => $item['booking_value'],
                        'title' => $item['title'],
                        'image_path' => 'cleaning-home-types/'.$item['image'],
                        'external_image_url' => null,
                        'sort_order' => $item['sort_order'],
                        'is_active' => true,
                    ],
                );

            if ($type->trashed()) {
                $type->restore();
            }
        }
    }

    /**
     * @return array<int, array{
     *     section: string,
     *     code: string,
     *     booking_value: string,
     *     title: string,
     *     image: string,
     *     sort_order: int
     * }>
     */
    private function items(): array
    {
        return [
            [
                'section' => CleaningHomeType::SECTION_PROPERTY,
                'code' => 'villa',
                'booking_value' => 'villa',
                'title' => 'فيلا دوبلكس',
                'image' => 'villa_image.png',
                'sort_order' => 10,
            ],
            [
                'section' => CleaningHomeType::SECTION_PROPERTY,
                'code' => 'office',
                'booking_value' => 'office',
                'title' => 'مكتب',
                'image' => 'cleaning_banner.png',
                'sort_order' => 20,
            ],
            [
                'section' => CleaningHomeType::SECTION_PROPERTY,
                'code' => 'apartment',
                'booking_value' => 'apartment',
                'title' => 'شقة',
                'image' => 'home_image.png',
                'sort_order' => 30,
            ],
            [
                'section' => CleaningHomeType::SECTION_PROPERTY,
                'code' => 'studio',
                'booking_value' => 'studio',
                'title' => 'استديو',
                'image' => 'studio_image.png',
                'sort_order' => 40,
            ],
            [
                'section' => CleaningHomeType::SECTION_OCCASION,
                'code' => 'family_dinner',
                'booking_value' => 'family_dinner',
                'title' => 'عشاء عائلي',
                'image' => 'family_dinner.png',
                'sort_order' => 10,
            ],
            [
                'section' => CleaningHomeType::SECTION_OCCASION,
                'code' => 'birthday',
                'booking_value' => 'birthday',
                'title' => 'حفلة عيد ميلاد',
                'image' => 'party.png',
                'sort_order' => 20,
            ],
            [
                'section' => CleaningHomeType::SECTION_OCCASION,
                'code' => 'large_gathering',
                'booking_value' => 'large_gathering',
                'title' => 'عزيمة كبيرة',
                'image' => 'big_launch.png',
                'sort_order' => 30,
            ],
            [
                'section' => CleaningHomeType::SECTION_OCCASION,
                'code' => 'funeral',
                'booking_value' => 'funeral',
                'title' => 'عزاء',
                'image' => 'aza.png',
                'sort_order' => 40,
            ],
        ];
    }
}
