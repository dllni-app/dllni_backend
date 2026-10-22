<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

it('requires authentication to fetch supermarket carts', function (): void {
    $this->getJson('/api/v1/user/supermarket/carts')
        ->assertUnauthorized();
});

it('returns each supermarket cart with its own store and items', function (): void {
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

    $add = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    $response = $this->getJson('/api/v1/user/supermarket/carts');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $add->json('data.id'))
        ->assertJsonPath('data.0.merchant.id', $store->id)
        ->assertJsonPath('data.0.items.0.productId', $product->id)
        ->assertJsonPath('data.0.items.0.quantity', 2)
        ->assertJsonMissingPath('data.0.merchantGroups');
});
