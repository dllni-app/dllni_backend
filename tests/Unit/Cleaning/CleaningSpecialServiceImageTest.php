<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\Cleaning\Models\CleaningSpecialService;

it('keeps legacy external special-service image urls compatible', function (): void {
    $service = new CleaningSpecialService([
        'image_url' => 'https://cdn.example.test/special-service.png',
    ]);

    expect($service->imageUrl())->toBe('https://cdn.example.test/special-service.png');
});

it('prefers an uploaded public-disk image over the legacy external url', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put(
        'cleaning-special-services/special-service.png',
        'fake-image-content',
    );

    $service = new CleaningSpecialService([
        'image_path' => 'cleaning-special-services/special-service.png',
        'image_url' => 'https://cdn.example.test/legacy.png',
    ]);

    $resolved = (string) $service->imageUrl();

    expect(str_ends_with(
        $resolved,
        '/storage/cleaning-special-services/special-service.png',
    ))->toBeTrue()
        ->and($resolved)->not->toBe('https://cdn.example.test/legacy.png');
});
