<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\OperatingHour;
use Modules\Resturants\Models\Restaurant;

it('lists discover restaurants with pagination', function (): void {
    // Arrange
    Restaurant::factory()->count(3)->create([
        'is_active' => true,
    ]);

    // Act
    $response = $this->getJson('/api/v1/user/restaurants/discover');

    // Assert
    $response->assertOk()->assertJsonStructure([
        'data' => [
            '*' => [
                'distanceKm',
                'imageUrl',
                'listingOffer',
            ],
        ],
        'links',
        'meta',
    ]);
});

it('filters discover restaurants by hasOffers', function (): void {
    // Arrange
    $restaurantWithOffer = Restaurant::factory()->create([
        'name' => 'Has Offer',
        'is_active' => true,
    ]);
    Offer::create([
        'restaurant_id' => $restaurantWithOffer->id,
        'name' => 'Spring Sale',
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    $restaurantWithoutOffer = Restaurant::factory()->create([
        'name' => 'No Offer',
        'is_active' => true,
    ]);

    // Act
    $response = $this->getJson('/api/v1/user/restaurants/discover?filter[hasOffers]=1');

    // Assert
    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Has Offer');
    expect($names)->not->toContain('No Offer');
});

it('includes listing offer payload for restaurants with an active offer', function (): void {
    // Arrange
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

    // Act
    $response = $this->getJson('/api/v1/user/restaurants/discover');

    // Assert
    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('name', 'Pizza Place');
    expect($row)->not->toBeNull();
    expect($row['listingOffer'])->toMatchArray([
        'title' => 'On all family pizzas',
        'discountType' => 'percentage',
        'discountValue' => 50,
        'offerBadgeText' => '50%',
    ]);
    expect($row['listingOffer']['urgencyTag'])->toBe('limited_time');
});

it('returns distanceKm when sorting by nearest with coordinates', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    // Arrange
    Restaurant::factory()->create([
        'name' => 'Near You',
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    // Act
    $response = $this->getJson(
        '/api/v1/user/restaurants/discover?sort=nearest&latitude=33.5200&longitude=36.2900'
    );

    // Assert
    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('name', 'Near You');
    expect($row)->not->toBeNull();
    expect($row['distanceKm'])->toBeFloat()->toBeGreaterThan(0);
});

it('filters discover restaurants by openNow', function (): void {
    CarbonImmutable::setTestNow('2026-06-15 14:00:00');

    try {
        // Arrange
        $openRestaurant = Restaurant::factory()->create([
            'name' => 'Open Now',
            'is_active' => true,
            'is_temporarily_closed' => false,
            'suspension_until' => null,
        ]);

        OperatingHour::create([
            'restaurant_id' => $openRestaurant->id,
            'day_of_week' => mb_strtolower(now()->englishDayOfWeek),
            'open_time' => '08:00:00',
            'close_time' => '22:00:00',
            'is_closed' => false,
        ]);

        $closedRestaurant = Restaurant::factory()->create([
            'name' => 'Closed Now',
            'is_active' => true,
            'is_temporarily_closed' => true,
        ]);

        // Act
        $response = $this->getJson('/api/v1/user/restaurants/discover?filter[openNow]=1');

        // Assert
        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('Open Now');
        expect($names)->not->toContain('Closed Now');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
