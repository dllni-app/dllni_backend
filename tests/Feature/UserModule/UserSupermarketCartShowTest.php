<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

it('requires authentication to list supermarket carts', function (): void {
    $this->getJson('/api/v1/user/supermarket/carts')
        ->assertUnauthorized();
});

it('returns store-scoped supermarket cart detail and a list of carts', function (): void {
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

    $created = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    $cartId = (int) $created->json('data.id');
    expect($cartId)->toBeGreaterThan(0);

    $this->getJson('/api/v1/user/supermarket/carts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cartId)
        ->assertJsonPath('data.0.merchant.id', $store->id);

    $detail = $this->getJson("/api/v1/user/supermarket/carts/{$cartId}");

    $detail->assertOk()
        ->assertJsonPath('data.id', $cartId)
        ->assertJsonPath('data.storeId', $store->id)
        ->assertJsonPath('data.merchant.id', $store->id)
        ->assertJsonPath('data.items.0.productId', $product->id)
        ->assertJsonPath('data.items.0.quantity', 2);

    expect($detail->json('data'))->not->toHaveKey('merchantGroups');
});
