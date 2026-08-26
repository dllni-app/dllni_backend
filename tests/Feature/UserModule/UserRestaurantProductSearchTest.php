<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

beforeEach(function (): void {
    config()->set('services.dallelni_search.auth_token', 'dallelni-ai');
    config()->set(
        'services.dallelni_search.restaurant_products_base_url',
        'https://dallelni.karriya.ai/restaurant-products'
    );
});

it('falls back to local search when semantic restaurant search returns no results', function (): void {
    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'مشروبات',
    ]);

    $drink = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'بيبسي',
        'description' => 'عبوة 330 مل',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/restaurant-products/search' => Http::response([
            'results' => [],
        ]),
    ]);

    $response = $this->getJson(
        '/api/v1/user/restaurants/products/search?text='.urlencode('مشروب').'&page=1&perPage=10'
    );

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($drink->id);
});

it('keeps inactive restaurant products out of local fallback search', function (): void {
    $activeRestaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);
    $inactiveRestaurant = Restaurant::factory()->create([
        'is_active' => false,
    ]);

    $activeCategory = Category::factory()->create([
        'restaurant_id' => $activeRestaurant->id,
        'name' => 'مشروبات',
    ]);
    $inactiveCategory = Category::factory()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'name' => 'مشروبات',
    ]);

    $visible = Product::factory()->create([
        'restaurant_id' => $activeRestaurant->id,
        'category_id' => $activeCategory->id,
        'name' => 'كولا',
        'description' => 'عبوة باردة',
        'is_available' => true,
    ]);
    $hidden = Product::factory()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'category_id' => $inactiveCategory->id,
        'name' => 'مشروب مخفي',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/restaurant-products/search' => Http::response([
            'results' => [],
        ]),
    ]);

    $response = $this->getJson(
        '/api/v1/user/restaurants/products/search?text='.urlencode('مشروب').'&page=1&perPage=10'
    );

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($visible->id);
    expect($ids)->not->toContain($hidden->id);
});
