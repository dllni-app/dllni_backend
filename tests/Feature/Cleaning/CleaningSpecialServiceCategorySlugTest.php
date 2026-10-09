<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningSpecialServiceCategory;

it('generates a unique service category slug from its Arabic name without exposing a manual field', function (): void {
    $first = CleaningSpecialServiceCategory::query()->create([
        'name' => 'تنظيف المطابخ',
        'sort_order' => 10,
        'is_active' => true,
    ]);
    $second = CleaningSpecialServiceCategory::query()->create([
        'name' => 'تنظيف المطابخ',
        'sort_order' => 20,
        'is_active' => true,
    ]);

    expect($first->slug)->toBeString()->not->toBeEmpty()
        ->and($first->slug)->not->toContain(' ')
        ->and($second->slug)->not->toBe($first->slug);

    $originalSlug = $first->slug;
    $first->update(['name' => 'خدمات المطابخ']);
    expect($first->refresh()->slug)->toBe($originalSlug);
});
