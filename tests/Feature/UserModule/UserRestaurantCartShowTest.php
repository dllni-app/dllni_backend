<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('requires authentication to fetch restaurant cart', function (): void {
    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertUnauthorized();
});

it('returns empty cart payload when user has no restaurant cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertOk()->assertJsonPath('data.id', null);
    expect($response->json('data.merchantGroups'))->toBeArray()->toBeEmpty();
});

it('returns cart items grouped by merchant after adding to cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 25,
        'discounted_price' => null,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 3,
    ])->assertCreated();

    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertOk()
        ->assertJsonPath('data.merchantGroups.0.merchant.id', $restaurant->id)
        ->assertJsonPath('data.merchantGroups.0.items.0.productId', $product->id)
        ->assertJsonPath('data.merchantGroups.0.items.0.quantity', 3)
        ->assertJsonPath('data.merchantGroups.0.items.0.name', $product->name);

    expect($response->json('data.id'))->toBeInt();
});

it('includes merchant and line item image urls on restaurant cart', function (): void {
    Storage::fake('public');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $restaurant->addMedia(UploadedFile::fake()->image('shop.jpg'))
        ->toMediaCollection('primary-image');
    $restaurant->addMedia(UploadedFile::fake()->image('banner.jpg'))
        ->toMediaCollection('banner-image');

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 18,
        'discounted_price' => null,
        'name' => 'Cart dish',
    ]);
    $product->addMedia(UploadedFile::fake()->image('dish-main.jpg'))
        ->toMediaCollection('primary-image');
    $product->addMedia(UploadedFile::fake()->image('dish-1.jpg'))
        ->toMediaCollection('images');

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertOk();
    $response->assertJsonPath('data.merchantGroups.0.merchant.id', $restaurant->id);
    expect($response->json('data.merchantGroups.0.merchant.primaryImageUrl'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.merchantGroups.0.merchant.bannerImageUrl'))->toBeString()->not->toBeEmpty();

    expect($response->json('data.merchantGroups.0.items.0.primaryImageUrl'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.merchantGroups.0.items.0.images'))->toBeArray()->not->toBeEmpty();
    $response->assertJsonPath('data.merchantGroups.0.items.0.name', 'Cart dish');
});

it('shows items from multiple restaurants in the same cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurantA = Restaurant::factory()->create(['is_active' => true]);
    $restaurantB = Restaurant::factory()->create(['is_active' => true]);

    $productA = Product::factory()->create([
        'restaurant_id' => $restaurantA->id,
        'is_available' => true,
        'price' => 10,
    ]);
    $productB = Product::factory()->create([
        'restaurant_id' => $restaurantB->id,
        'is_available' => true,
        'price' => 20,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $productA->id,
        'quantity' => 1,
    ])->assertCreated();

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $productB->id,
        'quantity' => 2,
    ])->assertCreated();

    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertOk();
    expect($response->json('data.merchantGroups'))->toHaveCount(2);
    expect((float) $response->json('data.amounts.subtotal'))->toBe(50.0);

    $this->assertDatabaseCount('carts', 1);
    $this->assertDatabaseCount('cart_items', 2);
});
