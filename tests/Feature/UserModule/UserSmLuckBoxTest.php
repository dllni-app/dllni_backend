<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\CategoryFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RestaurantFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\CuisineType;

it('requires auth for restaurant luck box endpoints', function (): void {
    $this->getJson('/api/v1/user/restaurants/luck-box/options')
        ->assertUnauthorized();

    $this->postJson('/api/v1/user/restaurants/luck-box/suggest', [
        'groupSize' => 2,
        'budgetPerPerson' => 100,
    ])->assertUnauthorized();
});

it('removes legacy supermarket luck box endpoints', function (): void {
    $this->getJson('/api/v1/user/supermarket/luck-box/options')->assertNotFound();
    $this->postJson('/api/v1/user/supermarket/luck-box/suggest', [
        'groupSize' => 2,
        'budgetPerPerson' => 100,
    ])->assertNotFound();
});

it('returns restaurant luck box options', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = RestaurantFactory::new()->create(['is_active' => true]);

    $cuisine = CuisineType::query()->create([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);
    $restaurant->cuisineTypes()->attach($cuisine->id);

    $response = $this->getJson('/api/v1/user/restaurants/luck-box/options');

    $response->assertOk()->assertJsonStructure([
        'restrictions',
        'cuisineTypes',
    ]);

    expect($response->json('cuisineTypes.0.id'))->toBe($cuisine->id);
});

it('returns restaurant luck box bundle suggestions', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = RestaurantFactory::new()->create([
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'estimated_preparation_time' => 20,
    ]);

    $cuisine = CuisineType::query()->create([
        'name' => 'Mediterranean',
        'slug' => 'mediterranean',
    ]);
    $restaurant->cuisineTypes()->attach($cuisine->id);

    $catA = CategoryFactory::new()->create(['restaurant_id' => $restaurant->id, 'name' => 'Main']);
    $catB = CategoryFactory::new()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sides']);

    ProductFactory::new()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $catA->id,
        'name' => 'Chicken Shawarma',
        'price' => 50,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);
    ProductFactory::new()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $catA->id,
        'name' => 'Falafel Plate',
        'price' => 80,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);
    ProductFactory::new()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $catB->id,
        'name' => 'Lentil Soup',
        'price' => 120,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);

    $response = $this->postJson('/api/v1/user/restaurants/luck-box/suggest', [
        'groupSize' => 2,
        'budgetPerPerson' => 150,
        'restaurantId' => $restaurant->id,
        'cuisineTypeId' => $cuisine->id,
    ]);

    $response->assertOk()->assertJsonStructure([
        'budget' => ['groupSize', 'budgetPerPerson', 'total'],
        'bundles' => [
            [
                'label',
                'labelAr',
                'restaurant' => ['id', 'name'],
                'totalProducts',
                'itemsDescription',
                'totalPrice',
                'estimatedMinutes',
                'lineItems' => [
                    [
                        'productId',
                        'name',
                        'quantity',
                        'unitPrice',
                        'lineTotal',
                        'imageUrl',
                    ],
                ],
            ],
        ],
    ]);

    expect($response->json('bundles'))->not->toBeEmpty();
});

it('applies default 10km radius when coordinates are provided', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $nearRestaurant = RestaurantFactory::new()->create([
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    $farRestaurant = RestaurantFactory::new()->create([
        'is_active' => true,
        'latitude' => 34.4000,
        'longitude' => 36.9000,
    ]);

    $nearCategory = CategoryFactory::new()->create(['restaurant_id' => $nearRestaurant->id]);
    $farCategory = CategoryFactory::new()->create(['restaurant_id' => $farRestaurant->id]);

    ProductFactory::new()->create([
        'restaurant_id' => $nearRestaurant->id,
        'category_id' => $nearCategory->id,
        'name' => 'Nearby Burger',
        'price' => 100,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);

    ProductFactory::new()->create([
        'restaurant_id' => $farRestaurant->id,
        'category_id' => $farCategory->id,
        'name' => 'Far Pizza',
        'price' => 100,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);

    $response = $this->postJson('/api/v1/user/restaurants/luck-box/suggest', [
        'groupSize' => 2,
        'budgetPerPerson' => 120,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ])->assertOk();

    $restaurantIds = collect($response->json('bundles'))->pluck('restaurant.id')->unique()->values();
    expect($restaurantIds)->toContain($nearRestaurant->id);
    expect($restaurantIds)->not->toContain($farRestaurant->id);
});
