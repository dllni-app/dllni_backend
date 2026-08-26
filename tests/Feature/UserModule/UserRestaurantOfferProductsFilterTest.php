<?php

declare(strict_types=1);

use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('filters restaurant offer products by the selected offer id', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $selectedOffer = Offer::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_active' => true,
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'ends_at' => now()->addDays(5),
    ]);

    $otherOffer = Offer::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_active' => true,
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'ends_at' => now()->addDays(5),
    ]);

    $selectedProduct = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);
    $selectedProduct->offers()->attach($selectedOffer);

    $otherProduct = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);
    $otherProduct->offers()->attach($otherOffer);

    $response = $this->getJson(
        "/api/v1/user/restaurants/products/with-offers?restaurant_id={$restaurant->id}&offer_id={$selectedOffer->id}&per_page=100",
    );

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($selectedProduct->id);
    expect($response->json('data.0.activeOffers'))->toHaveCount(1);
    expect($response->json('data.0.activeOffers.0.id'))->toBe($selectedOffer->id);
});

it('validates the selected offer id', function (): void {
    $response = $this->getJson('/api/v1/user/restaurants/products/with-offers?offer_id=999999999');

    $response->assertUnprocessable()->assertJsonValidationErrors(['offer_id']);
});
