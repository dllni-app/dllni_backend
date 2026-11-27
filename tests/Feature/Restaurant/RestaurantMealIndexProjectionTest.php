<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Modules\Resturants\Jobs\SyncRestaurantMealIndex;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Services\RestaurantMealIndexDocument;

beforeEach(function (): void {
    config([
        'services.dallelni_search.meal_index_enabled' => true,
        'services.dallelni_search.restaurant_products_base_url' => 'https://index.test',
        'services.dallelni_search.auth_token' => 'test-only',
    ]);
    Bus::fake([SyncRestaurantMealIndex::class]);
    Http::fake(['https://index.test/*' => Http::response(['ok' => true])]);
});

it('exports the same visible catalog and semantic fields as incremental indexing', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true, 'is_temporarily_closed' => false]);
    $root = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
    $child = Category::factory()->create(['restaurant_id' => $restaurant->id, 'parent_id' => $root->id, 'is_active' => true]);
    $family = FoodDiscoveryCategory::create(['slug' => 'qa-shawarma', 'name_ar' => 'شاورما', 'name_en' => 'Shawarma', 'aliases' => ['شاورمه'], 'is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id, 'category_id' => $child->id,
        'catalog_role' => 'independent', 'classification_status' => 'reviewed',
        'product_kind' => 'main_dish', 'item_type' => 'sandwich',
        'primary_food_category_id' => $family->id, 'servings' => 1,
        'stock_quantity' => 5, 'is_available' => true,
    ]);
    $modifier = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $child->id, 'catalog_role' => 'modifier_only']);
    $this->getJson('/api/internal/ai/catalog/restaurant-products')->assertUnauthorized();
    $rows = collect($this->getJson('/api/internal/ai/catalog/restaurant-products', ['auth-token' => 'test-only'])
        ->assertOk()->json('data'))->keyBy('id');
    expect($rows->has($modifier->id))->toBeFalse();
    $document = RestaurantMealIndexDocument::fields($product);
    expect($rows[$product->id]['searchTags'])->toBe($document['search_tags'])
        ->toContain('شاورمه', 'Shawarma', 'servings:1');
    expect($rows[$product->id]['isAvailable'])->toBeTrue();
    (new SyncRestaurantMealIndex($product->id))->handle();
    Http::assertSent(fn ($request) => $request->url() === 'https://index.test/meals/upsert'
        && $request['product_id'] === $product->id && $request['search_tags'] === $document['search_tags']);

    $root->update(['is_active' => false]);
    Bus::assertDispatched(SyncRestaurantMealIndex::class, fn ($job) => $job->productId === $product->id && $job->afterCommit === true);
    $rows = collect($this->getJson('/api/internal/ai/catalog/restaurant-products', ['auth-token' => 'test-only'])
        ->assertOk()->json('data'))->keyBy('id');
    expect($rows->has($product->id))->toBeFalse();
    (new SyncRestaurantMealIndex($product->id))->handle();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://index.test/meals/'.$product->id);
});

it('refreshes dependency changes but not sibling order changes', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $family = FoodDiscoveryCategory::create(['slug' => 'qa-burger', 'name_ar' => 'برغر', 'is_active' => true]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id,
        'primary_food_category_id' => $family->id, 'stock_quantity' => 3, 'is_available' => true]);
    Bus::fake([SyncRestaurantMealIndex::class]);
    $category->update(['sort_order' => 99]);
    Bus::assertNotDispatched(SyncRestaurantMealIndex::class);
    $family->update(['aliases' => ['همبرغر']]);
    Bus::assertDispatched(SyncRestaurantMealIndex::class, fn ($job) => $job->productId === $product->id && $job->afterCommit === true);
    Bus::fake([SyncRestaurantMealIndex::class]);
    $restaurant->update(['is_temporarily_closed' => true]);
    Bus::assertDispatched(SyncRestaurantMealIndex::class, fn ($job) => $job->productId === $product->id);
    expect(RestaurantMealIndexDocument::fields($product->fresh())['is_available'])->toBeFalse();
});

it('allows the meal index worker to use a dedicated queue without changing the default', function (): void {
    config(['services.dallelni_search.meal_index_queue' => null]);
    expect((new SyncRestaurantMealIndex(1))->queue)->toBeNull();
    config(['services.dallelni_search.meal_index_queue' => 'restaurant-meal-index']);
    expect((new SyncRestaurantMealIndex(1))->queue)->toBe('restaurant-meal-index');
});
