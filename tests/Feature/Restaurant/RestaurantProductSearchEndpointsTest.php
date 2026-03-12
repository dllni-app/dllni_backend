<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\MasterProduct;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

function actingAsRestaurantSellerWithRestaurant(): array
{
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
        'is_active' => true,
    ]);

    Sanctum::actingAs($owner);

    return [$owner, $restaurant];
}

it('requires authentication for restaurant regular product search endpoint', function () {
    $response = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger');

    $response->assertUnauthorized();
});

it('validates required and bounded search filter parameters', function () {
    actingAsRestaurantSellerWithRestaurant();

    $missingSearch = $this->getJson('/api/v1/restaurant/search/products');
    $missingSearch->assertUnprocessable();
    $missingSearch->assertJsonValidationErrors(['filter.search']);

    $invalidPerPage = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger&perPage=99');
    $invalidPerPage->assertUnprocessable();
    $invalidPerPage->assertJsonValidationErrors(['perPage']);

    $invalidPriceRange = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger&filter[minPrice]=20&filter[maxPrice]=10');
    $invalidPriceRange->assertUnprocessable();
    $invalidPriceRange->assertJsonValidationErrors(['filter.maxPrice']);
});

it('rejects legacy serach key and requires filter.search', function () {
    actingAsRestaurantSellerWithRestaurant();

    $response = $this->getJson('/api/v1/restaurant/search/products?serach=burger');

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['filter.search']);
});

it('returns matches from product name and slug', function () {
    [, $restaurant] = actingAsRestaurantSellerWithRestaurant();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $nameMatch = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Chicken Burger Deluxe',
        'slug' => 'chicken-burger-deluxe',
    ]);

    $slugMatch = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'House Special',
        'slug' => 'house-burger-special',
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $response = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($nameMatch->id);
    expect($ids)->toContain($slugMatch->id);
});

it('excludes unavailable products and products from inactive restaurants by default', function () {
    [, $activeRestaurant] = actingAsRestaurantSellerWithRestaurant();
    $inactiveRestaurant = Restaurant::factory()->create(['is_active' => false]);

    $activeCategory = Category::factory()->create(['restaurant_id' => $activeRestaurant->id]);
    $inactiveCategory = Category::factory()->create(['restaurant_id' => $inactiveRestaurant->id]);

    $included = Product::factory()->create([
        'restaurant_id' => $activeRestaurant->id,
        'category_id' => $activeCategory->id,
        'name' => 'Burger Included',
        'slug' => 'burger-included',
        'is_available' => true,
    ]);

    $unavailable = Product::factory()->create([
        'restaurant_id' => $activeRestaurant->id,
        'category_id' => $activeCategory->id,
        'name' => 'Burger Unavailable',
        'slug' => 'burger-unavailable',
        'is_available' => false,
    ]);

    $inactive = Product::factory()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'category_id' => $inactiveCategory->id,
        'name' => 'Burger Inactive Restaurant',
        'slug' => 'burger-inactive-restaurant',
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($included->id);
    expect($ids)->not->toContain($inactive->id);
    expect($ids)->not->toContain($unavailable->id);
});

it('searches within current restaurant only', function () {
    [, $restaurant] = actingAsRestaurantSellerWithRestaurant();

    $otherRestaurant = Restaurant::factory()->create(['is_active' => true]);

    $categoryA = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $categoryB = Category::factory()->create(['restaurant_id' => $otherRestaurant->id]);

    $inCurrent = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $categoryA->id,
        'name' => 'Burger In Current',
        'slug' => 'burger-in-current',
    ]);

    $inOther = Product::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
        'category_id' => $categoryB->id,
        'name' => 'Burger In Other',
        'slug' => 'burger-in-other',
    ]);

    $global = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger');
    $global->assertOk();
    $globalIds = collect($global->json('data'))->pluck('id')->all();
    expect($globalIds)->toContain($inCurrent->id);
    expect($globalIds)->not->toContain($inOther->id);
});

it('supports category, masterProduct, price, discount, and low-stock filters', function () {
    [, $restaurant] = actingAsRestaurantSellerWithRestaurant();
    $categoryA = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $categoryB = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $masterA = MasterProduct::query()->create([
        'name' => 'Master A',
        'barcode' => '8800000000001',
        'unit' => App\Enums\MasterProductUnit::Piece,
        'brand' => 'Brand A',
        'description' => 'A',
        'is_active' => true,
    ]);
    $masterB = MasterProduct::query()->create([
        'name' => 'Master B',
        'barcode' => '8800000000002',
        'unit' => App\Enums\MasterProductUnit::Piece,
        'brand' => 'Brand B',
        'description' => 'B',
        'is_active' => true,
    ]);

    $target = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $categoryA->id,
        'master_product_id' => $masterA->id,
        'name' => 'Burger Target',
        'slug' => 'burger-target',
        'price' => 30,
        'discounted_price' => 20,
        'stock_quantity' => 5,
        'low_stock_threshold' => 5,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $categoryB->id,
        'master_product_id' => $masterB->id,
        'name' => 'Burger Other',
        'slug' => 'burger-other',
        'price' => 10,
        'discounted_price' => null,
        'stock_quantity' => 20,
        'low_stock_threshold' => 5,
    ]);

    $query = http_build_query([
        'filter' => [
            'search' => 'burger',
            'categoryId' => $categoryA->id,
            'masterProductId' => $masterA->id,
            'minPrice' => 25,
            'maxPrice' => 35,
            'hasDiscount' => true,
            'lowStock' => true,
        ],
    ]);

    $response = $this->getJson('/api/v1/restaurant/search/products?'.$query);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$target->id]);
});

it('orders by relevance then featured and newest tie-breakers when sort is missing', function () {
    [, $restaurant] = actingAsRestaurantSellerWithRestaurant();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $contains = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Super Burger Meal',
        'slug' => 'super-burger-meal',
        'is_featured' => true,
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $slugContains = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Deluxe Meal',
        'slug' => 'deluxe-burger',
        'is_featured' => true,
        'created_at' => now()->subMinutes(4),
        'updated_at' => now()->subMinutes(4),
    ]);

    $prefixFeaturedOlder = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Burger Classic',
        'slug' => 'burger-classic',
        'is_featured' => true,
        'created_at' => now()->subMinutes(3),
        'updated_at' => now()->subMinutes(3),
    ]);

    $prefixNotFeaturedNewer = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Burger Premium',
        'slug' => 'burger-premium',
        'is_featured' => false,
        'created_at' => now()->subMinutes(1),
        'updated_at' => now()->subMinutes(1),
    ]);

    $response = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger&filter[restaurantId]='.$restaurant->id);

    $response->assertOk();
    $orderedIds = collect($response->json('data'))->pluck('id')->values()->all();

    expect($orderedIds[0])->toBe($prefixFeaturedOlder->id);
    expect($orderedIds[1])->toBe($prefixNotFeaturedNewer->id);
    expect($orderedIds[2])->toBe($contains->id);
    expect($orderedIds[3])->toBe($slugContains->id);
});

it('supports explicit sort values', function () {
    [, $restaurant] = actingAsRestaurantSellerWithRestaurant();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $alpha = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Alpha Burger',
        'slug' => 'alpha-burger',
        'price' => 30,
    ]);

    $beta = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Beta Burger',
        'slug' => 'beta-burger',
        'price' => 10,
    ]);

    $byName = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger&sort=name');
    $byName->assertOk();
    $byNameIds = collect($byName->json('data'))->pluck('id')->all();
    expect($byNameIds[0])->toBe($alpha->id);

    $byPriceDesc = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger&sort=-price');
    $byPriceDesc->assertOk();
    $byPriceIds = collect($byPriceDesc->json('data'))->pluck('id')->all();
    expect($byPriceIds[0])->toBe($alpha->id);
    expect($byPriceIds[1])->toBe($beta->id);
});

it('returns paginated response with requested page and perPage', function () {
    [, $restaurant] = actingAsRestaurantSellerWithRestaurant();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    Product::factory()->count(3)->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Burger Item',
        'slug' => 'burger-item',
    ]);

    $response = $this->getJson('/api/v1/restaurant/search/products?filter[search]=burger&perPage=1&page=2');

    $response->assertOk();
    expect($response->json('meta.per_page'))->toBe(1);
    expect($response->json('meta.current_page'))->toBe(2);
    expect($response->json('data'))->toHaveCount(1);
});
