<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Restaurant;

it('returns exclusive restaurant offers for the home section', function (): void {
    $restaurant = Restaurant::factory()->create([
        'name' => 'Pizza Place',
        'is_active' => true,
    ]);

    Offer::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'On all family pizzas',
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/exclusive-offers');

    $response->assertOk();
    $response->assertJsonPath('exclusiveOffers.0.restaurantName', 'Pizza Place');
    $response->assertJsonPath('exclusiveOffers.0.offerDescription', 'On all family pizzas');
    $response->assertJsonPath('exclusiveOffers.0.offerBadgeText', '50%');
    $response->assertJsonPath('exclusiveOffers.0.discountType', 'percentage');
    $response->assertJsonPath('exclusiveOffers.0.discountValue', 50);
    $response->assertJsonPath('exclusiveOffers.0.urgencyTag', 'limited_time');
    expect($response->json('exclusiveOffers.0.offerId'))->toBeInt();
    expect($response->json('exclusiveOffers.0.restaurantId'))->toBe($restaurant->id);
});

it('excludes offers from inactive restaurants', function (): void {
    $inactiveRestaurant = Restaurant::factory()->create([
        'is_active' => false,
    ]);

    Offer::create([
        'restaurant_id' => $inactiveRestaurant->id,
        'name' => 'Ghost deal',
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/exclusive-offers');

    $response->assertOk();
    expect($response->json('exclusiveOffers'))->toBeArray()->toBeEmpty();
});

it('returns distanceKm when coordinates are provided on databases that support haversine', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    $restaurant = Restaurant::factory()->create([
        'name' => 'Near You',
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    Offer::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Lunch deal',
        'discount_type' => DiscountType::FixedAmount,
        'discount_value' => 2000,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    $response = $this->getJson(
        '/api/v1/user/restaurants/home/exclusive-offers?latitude=33.5200&longitude=36.2900'
    );

    $response->assertOk();
    $response->assertJsonPath('exclusiveOffers.0.distanceUnit', 'km');
    expect($response->json('exclusiveOffers.0.distanceKm'))->toBeFloat()->toBeGreaterThan(0);
});

it('respects the limit query parameter', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    foreach (range(1, 5) as $i) {
        Offer::create([
            'restaurant_id' => $restaurant->id,
            'name' => "Offer {$i}",
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 5,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
            'is_active' => true,
        ]);
    }

    $response = $this->getJson('/api/v1/user/restaurants/home/exclusive-offers?limit=2');

    $response->assertOk();
    expect($response->json('exclusiveOffers'))->toHaveCount(2);
});
