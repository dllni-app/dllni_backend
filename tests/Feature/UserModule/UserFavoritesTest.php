<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmProductFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmStore;

it('requires authentication for restaurant favorites', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $this->getJson('/api/v1/user/favorites/restaurants')->assertUnauthorized();
    $this->postJson("/api/v1/user/favorites/restaurants/{$restaurant->id}")->assertUnauthorized();
    $this->deleteJson("/api/v1/user/favorites/restaurants/{$restaurant->id}")->assertUnauthorized();
});

it('requires authentication for supermarket store favorites', function (): void {
    $owner = User::factory()->create();
    $store = SmStore::create([
        'owner_user_id' => $owner->id,
        'name' => 'Fresh Mart',
        'slug' => 'fresh-mart-'.fake()->unique()->numerify('####'),
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/user/favorites/supermarket/stores')->assertUnauthorized();
    $this->postJson("/api/v1/user/favorites/supermarket/stores/{$store->id}")->assertUnauthorized();
    $this->deleteJson("/api/v1/user/favorites/supermarket/stores/{$store->id}")->assertUnauthorized();
});

it('requires authentication for product favorites', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $this->getJson('/api/v1/user/favorites/products')->assertUnauthorized();
    $this->postJson("/api/v1/user/favorites/products/{$product->id}")->assertUnauthorized();
    $this->deleteJson("/api/v1/user/favorites/products/{$product->id}")->assertUnauthorized();
});

it('requires authentication for supermarket product favorites', function (): void {
    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $this->getJson('/api/v1/user/favorites/supermarket/products')->assertUnauthorized();
    $this->postJson("/api/v1/user/favorites/supermarket/products/{$product->id}")->assertUnauthorized();
    $this->deleteJson("/api/v1/user/favorites/supermarket/products/{$product->id}")->assertUnauthorized();
});

it('adds and lists restaurant favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Kebab House',
        'is_active' => true,
    ]);

    $create = $this->postJson("/api/v1/user/favorites/restaurants/{$restaurant->id}");
    $create->assertCreated()->assertJsonPath('restaurant.id', $restaurant->id);

    $again = $this->postJson("/api/v1/user/favorites/restaurants/{$restaurant->id}");
    $again->assertOk()->assertJsonPath('restaurant.id', $restaurant->id);

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(1);

    $list = $this->getJson('/api/v1/user/favorites/restaurants');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1);
    expect($list->json('data.0.name'))->toBe('Kebab House');
});

it('rejects favoriting an inactive restaurant', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => false]);

    $this->postJson("/api/v1/user/favorites/restaurants/{$restaurant->id}")
        ->assertStatus(422);
});

it('removes a restaurant favorite', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => Restaurant::class,
        'favorable_id' => $restaurant->id,
    ]);

    $this->deleteJson("/api/v1/user/favorites/restaurants/{$restaurant->id}")
        ->assertNoContent();

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('adds and lists supermarket store favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $owner = User::factory()->create();
    $store = SmStore::create([
        'owner_user_id' => $owner->id,
        'name' => 'City Market',
        'slug' => 'city-market-'.fake()->unique()->numerify('####'),
        'is_active' => true,
    ]);

    SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'discount_value' => 40,
        'ends_at' => now()->addDay(),
    ]);

    $create = $this->postJson("/api/v1/user/favorites/supermarket/stores/{$store->id}");
    $create->assertCreated()
        ->assertJsonPath('store.id', $store->id)
        ->assertJsonPath('store.isFavorited', true);
    expect((float) $create->json('store.highestOfferDiscountValue'))->toBe(40.0);

    $again = $this->postJson("/api/v1/user/favorites/supermarket/stores/{$store->id}");
    $again->assertOk()
        ->assertJsonPath('store.id', $store->id)
        ->assertJsonPath('store.isFavorited', true);
    expect((float) $again->json('store.highestOfferDiscountValue'))->toBe(40.0);

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(1);

    $list = $this->getJson('/api/v1/user/favorites/supermarket/stores');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1);
    expect($list->json('data.0.name'))->toBe('City Market');
    expect($list->json('data.0.isFavorited'))->toBeTrue();
    expect((float) $list->json('data.0.highestOfferDiscountValue'))->toBe(40.0);
});

it('rejects favoriting an inactive supermarket store', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $owner = User::factory()->create();
    $store = SmStore::create([
        'owner_user_id' => $owner->id,
        'name' => 'Closed Mart',
        'slug' => 'closed-mart-'.fake()->unique()->numerify('####'),
        'is_active' => false,
    ]);

    $this->postJson("/api/v1/user/favorites/supermarket/stores/{$store->id}")
        ->assertStatus(422);
});

it('removes a supermarket store favorite', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $owner = User::factory()->create();
    $store = SmStore::create([
        'owner_user_id' => $owner->id,
        'name' => 'Quick Shop',
        'slug' => 'quick-shop-'.fake()->unique()->numerify('####'),
        'is_active' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => SmStore::class,
        'favorable_id' => $store->id,
    ]);

    $this->deleteJson("/api/v1/user/favorites/supermarket/stores/{$store->id}")
        ->assertNoContent();

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('adds supermarket product favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Organic Apples',
        'is_available' => true,
    ]);
    $offer = SmOfferFactory::new()->create(['store_id' => $store->id]);
    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $product->id,
    ]);

    $create = $this->postJson("/api/v1/user/favorites/supermarket/products/{$product->id}");
    $create->assertCreated()
        ->assertJsonPath('product.id', $product->id)
        ->assertJsonPath('product.isFavorite', true)
        ->assertJsonPath('product.offers.0.id', $offer->id);

    $again = $this->postJson("/api/v1/user/favorites/supermarket/products/{$product->id}");
    $again->assertOk()->assertJsonPath('product.id', $product->id)->assertJsonPath('product.isFavorite', true);

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(1);

    $list = $this->getJson('/api/v1/user/favorites/supermarket/products');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1);
    expect($list->json('data.0.id'))->toBe($product->id);
    expect($list->json('data.0.isFavorite'))->toBeTrue();
    expect($list->json('data.0.offers.0.id'))->toBe($offer->id);
});

it('rejects favoriting an unavailable supermarket product', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => false,
    ]);

    $this->postJson("/api/v1/user/favorites/supermarket/products/{$product->id}")
        ->assertStatus(422);
});

it('rejects favoriting a supermarket product from an inactive store', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => false]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $this->postJson("/api/v1/user/favorites/supermarket/products/{$product->id}")
        ->assertStatus(422);
});

it('removes a supermarket product favorite', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => $product->getMorphClass(),
        'favorable_id' => $product->id,
    ]);

    $this->deleteJson("/api/v1/user/favorites/supermarket/products/{$product->id}")
        ->assertNoContent();

    expect(
        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', $product->getMorphClass())
            ->where('favorable_id', $product->id)
            ->exists()
    )->toBeFalse();
});

it('adds and lists product favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Chicken Shawarma',
        'is_available' => true,
    ]);

    $create = $this->postJson("/api/v1/user/favorites/products/{$product->id}");
    $create->assertCreated()->assertJsonPath('product.id', $product->id);

    $again = $this->postJson("/api/v1/user/favorites/products/{$product->id}");
    $again->assertOk()->assertJsonPath('product.id', $product->id);

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(1);

    $list = $this->getJson('/api/v1/user/favorites/products');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1);
    expect($list->json('data.0.name'))->toBe('Chicken Shawarma');
});

it('rejects favoriting an unavailable product', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => false,
    ]);

    $this->postJson("/api/v1/user/favorites/products/{$product->id}")
        ->assertStatus(422);
});

it('rejects favoriting a product from an inactive restaurant', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => false]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $this->postJson("/api/v1/user/favorites/products/{$product->id}")
        ->assertStatus(422);
});

it('removes a product favorite', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => Product::class,
        'favorable_id' => $product->id,
    ]);

    $this->deleteJson("/api/v1/user/favorites/products/{$product->id}")
        ->assertNoContent();

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(0);
});
