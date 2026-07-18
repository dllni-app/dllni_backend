<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningBanner;
use Modules\Cleaning\Models\CleaningHomeType;

it('returns public cleaning home content with active types ordered by section sort order', function (): void {
    CleaningHomeType::query()->forceDelete();
    CleaningBanner::query()->delete();

    CleaningBanner::factory()->create([
        'title' => 'Cleaning banner',
        'sort_order' => 1,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    $propertySecond = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'office_premium',
        'booking_value' => 'office',
        'title' => 'مكتب فاخر',
        'external_image_url' => 'https://example.com/office.jpg',
        'sort_order' => 20,
        'is_active' => true,
    ]);

    $propertyFirst = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'small_apartment',
        'booking_value' => 'apartment',
        'title' => 'شقة صغيرة',
        'external_image_url' => 'https://example.com/apartment.jpg',
        'sort_order' => 10,
        'is_active' => true,
    ]);

    CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'hidden_villa',
        'booking_value' => 'villa',
        'title' => 'فيلا مخفية',
        'external_image_url' => 'https://example.com/villa.jpg',
        'sort_order' => 1,
        'is_active' => false,
    ]);

    $occasion = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_OCCASION,
        'code' => 'graduation_party',
        'booking_value' => 'other',
        'title' => 'حفلة تخرج',
        'external_image_url' => 'https://example.com/graduation.jpg',
        'sort_order' => 5,
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/user/cleaning/home/content')
        ->assertOk()
        ->assertJsonCount(1, 'banners')
        ->assertJsonCount(2, 'propertyTypes')
        ->assertJsonCount(1, 'occasionTypes')
        ->assertJsonPath('propertyTypes.0.id', $propertyFirst->id)
        ->assertJsonPath('propertyTypes.0.code', 'small_apartment')
        ->assertJsonPath('propertyTypes.0.value', 'apartment')
        ->assertJsonPath('propertyTypes.0.imageUrl', 'https://example.com/apartment.jpg')
        ->assertJsonPath('propertyTypes.1.id', $propertySecond->id)
        ->assertJsonPath('occasionTypes.0.id', $occasion->id)
        ->assertJsonPath('occasionTypes.0.value', 'other');
});
