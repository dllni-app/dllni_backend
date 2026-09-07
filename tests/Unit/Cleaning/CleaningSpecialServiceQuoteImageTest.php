<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceDirtinessRule;
use Modules\Cleaning\Services\CleaningSpecialServiceQuoteService;

it('uses the resolved uploaded image URL in special-service quote payloads', function (): void {
    $service = CleaningSpecialService::query()->create([
        'name' => 'Agent B image quote',
        'image_path' => 'cleaning-special-services/quote.png',
        'image_url' => 'https://legacy.example.test/quote.png',
        'pricing_unit' => 'piece',
        'base_unit_price' => 50,
        'is_active' => true,
    ]);
    CleaningSpecialServiceDirtinessRule::query()->create([
        'cleaning_special_service_id' => $service->id,
        'dirtiness_level' => 'normal',
        'price_multiplier' => 1,
        'is_active' => true,
    ]);

    $quote = app(CleaningSpecialServiceQuoteService::class)->quote([[
        'specialServiceId' => $service->id,
        'quantity' => 1,
        'dirtinessLevel' => 'normal',
    ]]);

    expect($quote['lines'][0]['imageUrl'])->toBe($service->fresh()->imageUrl())
        ->and($quote['lines'][0]['imageUrl'])->not->toBe('https://legacy.example.test/quote.png');
});
