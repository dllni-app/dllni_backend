<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

it('requires authentication to fetch supermarket cart', function (): void {
    $this->getJson('/api/v1/user/supermarket/cart')
        ->assertUnauthorized();
});

it('returns flat items and merchant groups for supermarket cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
        'price' => 14,
    ]);

    $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    $response = $this->getJson('/api/v1/user/supermarket/cart');

    $response->assertOk()
        ->assertJsonPath('data.merchant.id', $store->id)
        ->assertJsonPath('data.items.0.productId', $product->id)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.merchantGroups.0.merchant.id', $store->id)
        ->assertJsonPath('data.merchantGroups.0.items.0.productId', $product->id)
        ->assertJsonPath('data.merchantGroups.0.items.0.quantity', 2);
});

