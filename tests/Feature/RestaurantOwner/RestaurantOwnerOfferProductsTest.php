<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
        'is_active' => true,
    ]);

    $this->category = Category::factory()->create([
        'restaurant_id' => $this->restaurant->id,
    ]);

    $this->product = Product::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'category_id' => $this->category->id,
        'is_available' => true,
    ]);

    Sanctum::actingAs($this->owner);
});

it('persists linked products when an owner creates an offer and exposes them to the user app', function () {
    $response = $this->postJson('/api/v1/restaurant-owner/offers', [
        'name' => 'Linked products offer',
        'discountType' => 'percentage',
        'discountValue' => 15,
        'startsAt' => now()->subMinute()->toISOString(),
        'endsAt' => now()->addDay()->toISOString(),
        'isActive' => true,
        'productIds' => [$this->product->id],
    ]);

    $response->assertSuccessful();
    $offerId = (int) $response->json('data.id');

    expect($offerId)->toBeGreaterThan(0);
    $this->assertDatabaseHas('offer_product', [
        'offer_id' => $offerId,
        'product_id' => $this->product->id,
    ]);

    $this->getJson('/api/v1/user/restaurants/home/exclusive-offers')
        ->assertOk()
        ->assertJsonPath('exclusiveOffers.0.offerId', $offerId)
        ->assertJsonPath('exclusiveOffers.0.products.0.id', $this->product->id);
});

it('rejects linking a product that belongs to another restaurant', function () {
    $otherRestaurant = Restaurant::factory()->create(['is_active' => true]);
    $otherCategory = Category::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
    ]);
    $otherProduct = Product::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
        'category_id' => $otherCategory->id,
    ]);

    $this->postJson('/api/v1/restaurant-owner/offers', [
        'name' => 'Invalid linked product offer',
        'discountType' => 'percentage',
        'discountValue' => 10,
        'startsAt' => now()->toISOString(),
        'endsAt' => now()->addDay()->toISOString(),
        'isActive' => true,
        'productIds' => [$otherProduct->id],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['productIds.0']);
});
