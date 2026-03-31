<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

it('returns nearest restaurants with cuisines, delivery window, and discount badge', function (): void {
    $italian = CuisineType::create(['name' => 'Italian', 'slug' => 'italian']);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Italian Chef',
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'average_rating' => 4.5,
        'estimated_preparation_time' => 25,
    ]);
    $restaurant->cuisineTypes()->attach($italian->id);

    Offer::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Winter promo',
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/nearest-restaurants');

    $response->assertOk();
    $response->assertJsonPath('nearestRestaurants.0.name', 'Italian Chef');
    $response->assertJsonPath('nearestRestaurants.0.rating', 4.5);
    $response->assertJsonPath('nearestRestaurants.0.discountOfferBadge', '20%');
    $response->assertJsonPath('nearestRestaurants.0.estimatedDeliveryMinutesMin', 20);
    $response->assertJsonPath('nearestRestaurants.0.estimatedDeliveryMinutesMax', 40);
    $response->assertJsonPath('nearestRestaurants.0.cuisineNames.0', 'Italian');
    $response->assertJsonPath('nearestRestaurants.0.cuisineSummary', 'Italian');
    $response->assertJsonPath('nearestRestaurants.0.isFavorited', false);
    $response->assertJsonPath('nearestRestaurants.0.isMostRequested', false);
});

it('returns distanceKm when coordinates are provided on databases that support haversine', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    Restaurant::factory()->create([
        'name' => 'Near Pin',
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    $response = $this->getJson(
        '/api/v1/user/restaurants/home/nearest-restaurants?latitude=33.5200&longitude=36.2900'
    );

    $response->assertOk();
    $response->assertJsonPath('nearestRestaurants.0.distanceUnit', 'km');
    expect($response->json('nearestRestaurants.0.distanceKm'))->toBeFloat()->toBeGreaterThan(0);
});

it('marks isMostRequested when the restaurant has enough recent completed orders', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    Order::factory()->count(5)->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed,
        'created_at' => now()->subDays(5),
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/nearest-restaurants');

    $response->assertOk();
    $row = collect($response->json('nearestRestaurants'))->firstWhere('name', $restaurant->name);
    expect($row)->not->toBeNull();
    expect($row['isMostRequested'])->toBeTrue();
    expect($row['popularOrdersCount'])->toBe(5);
});

it('sets isFavorited when the restaurant is in the authenticated user favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => Restaurant::class,
        'favorable_id' => $restaurant->id,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/nearest-restaurants');

    $response->assertOk();
    $row = collect($response->json('nearestRestaurants'))->firstWhere('id', $restaurant->id);
    expect($row['isFavorited'])->toBeTrue();
});

it('excludes inactive restaurants', function (): void {
    Restaurant::factory()->inactive()->create(['name' => 'Closed Kitchen']);

    $response = $this->getJson('/api/v1/user/restaurants/home/nearest-restaurants');

    $response->assertOk();
    $names = collect($response->json('nearestRestaurants'))->pluck('name')->all();
    expect($names)->not->toContain('Closed Kitchen');
});
