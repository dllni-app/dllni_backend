<?php

declare(strict_types=1);

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
        'data',
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

it('filters discover restaurants by openNow', function (): void {
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
        'open_time' => now()->subHour()->format('H:i:s'),
        'close_time' => now()->addHour()->format('H:i:s'),
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
});
