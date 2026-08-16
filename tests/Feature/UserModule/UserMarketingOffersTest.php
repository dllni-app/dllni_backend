<?php

declare(strict_types=1);

use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

it('lists currently valid homepage banners as image-only payloads with pagination', function (): void {
    $activeBanner = MarketingOffer::factory()->create([
        'title' => 'Legacy internal title',
        'discount_label' => 'Legacy internal label',
        'promo_code' => 'LEGACY',
        'theme' => MarketingOfferTheme::Orange,
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'sort_order' => 1,
    ]);

    MarketingOffer::factory()->create([
        'title' => 'Expired',
        'discount_label' => 'Expired',
        'is_active' => true,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
        'sort_order' => 0,
    ]);

    $response = $this->getJson('/api/v1/user/offers?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0'))->toBe([
        'id' => $activeBanner->id,
        'imageUrl' => null,
    ]);
    expect($response->json('meta.total'))->toBe(1);
});

it('returns a single valid homepage banner as an image-only payload', function (): void {
    $banner = MarketingOffer::factory()->create([
        'title' => 'Legacy internal title',
        'discount_label' => 'Legacy internal label',
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $response = $this->getJson("/api/v1/user/offers/{$banner->id}");

    $response->assertOk();
    expect($response->json('data'))->toBe([
        'id' => $banner->id,
        'imageUrl' => null,
    ]);
});

it('returns not found for inactive or out of window offer', function (): void {
    $offer = MarketingOffer::factory()->create([
        'is_active' => false,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $this->getJson("/api/v1/user/offers/{$offer->id}")->assertNotFound();
});
