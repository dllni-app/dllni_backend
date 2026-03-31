<?php

declare(strict_types=1);

use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

it('lists currently valid marketing offers with pagination', function (): void {
    MarketingOffer::factory()->create([
        'title' => 'للمستخدمين الجدد',
        'discount_label' => 'خصم 100%',
        'promo_code' => 'FREEDELIVERY',
        'theme' => MarketingOfferTheme::Orange,
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'sort_order' => 1,
    ]);

    MarketingOffer::factory()->create([
        'title' => 'Expired',
        'discount_label' => 'خصم 10%',
        'is_active' => true,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
        'sort_order' => 0,
    ]);

    $response = $this->getJson('/api/v1/user/offers?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.title'))->toBe('للمستخدمين الجدد');
    expect($response->json('data.0.discountLabel'))->toBe('خصم 100%');
    expect($response->json('data.0.promoCode'))->toBe('FREEDELIVERY');
    expect($response->json('data.0.theme'))->toBe('orange');
    expect($response->json('data.0.imageUrl'))->toBeNull();
    expect($response->json('meta.total'))->toBe(1);
});

it('returns a single valid offer', function (): void {
    $offer = MarketingOffer::factory()->create([
        'title' => 'متجر النور',
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $response = $this->getJson("/api/v1/user/offers/{$offer->id}");

    $response->assertOk();
    expect($response->json('data.title'))->toBe('متجر النور');
});

it('returns not found for inactive or out of window offer', function (): void {
    $offer = MarketingOffer::factory()->create([
        'is_active' => false,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $this->getJson("/api/v1/user/offers/{$offer->id}")->assertNotFound();
});
