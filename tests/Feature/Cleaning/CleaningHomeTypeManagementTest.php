<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningHomeType;

it('generates internal values and appends new types to the end of their section', function (): void {
    CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'existing_property',
        'booking_value' => 'apartment',
        'title' => 'نوع موجود',
        'image_path' => 'cleaning-home-types/existing.png',
        'sort_order' => 7,
        'is_active' => true,
    ]);

    $propertyType = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'title' => 'تنظيف خاص',
        'image_path' => 'cleaning-home-types/special.png',
        'is_active' => true,
    ]);

    $occasionType = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_OCCASION,
        'title' => 'مناسبة خاصة',
        'image_path' => 'cleaning-home-types/occasion.png',
        'is_active' => true,
    ]);

    expect($propertyType->code)
        ->not->toBeEmpty()
        ->and(strlen($propertyType->code))->toBeLessThanOrEqual(100)
        ->and($propertyType->booking_value)->toBe($propertyType->code)
        ->and($propertyType->sort_order)->toBe(8)
        ->and($occasionType->sort_order)->toBe(1);
});

it('keeps automatically generated codes unique inside the same section', function (): void {
    $firstType = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'title' => 'تنظيف مميز',
        'image_path' => 'cleaning-home-types/first.png',
        'is_active' => true,
    ]);

    $secondType = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'title' => 'تنظيف مميز',
        'image_path' => 'cleaning-home-types/second.png',
        'is_active' => true,
    ]);

    expect($secondType->code)
        ->not->toBe($firstType->code)
        ->toStartWith($firstType->code.'_');
});
