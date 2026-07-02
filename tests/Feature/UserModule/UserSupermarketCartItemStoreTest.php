<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

it('creates separate supermarket carts for products from different stores', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $firstStore = SmStoreFactory::new()->create();
    $secondStore = SmStoreFactory::new()->create();

    $firstCategory = SmCategoryFactory::new()->create(['store_id' => $firstStore->id]);
    $secondCategory = SmCategoryFactory::new()->create(['store_id' => $secondStore->id]);

    $firstProduct = SmProductFactory::new()->create([
        'store_id' => $firstStore->id,
        'category_id' => $firstCategory->id,
        'is_available' => true,
        'price' => 14,
    ]);
    $secondProduct = SmProductFactory::new()->create([
        'store_id' => $secondStore->id,
        'category_id' => $secondCategory->id,
        'is_available' => true,
        'price' => 19,
    ]);

    $firstAddResponse = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $firstProduct->id,
        'quantity' => 1,
    ])->assertCreated();

    $firstCartId = (int) $firstAddResponse->json('data.id');

    $secondAddResponse = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $secondProduct->id,
        'quantity' => 2,
    ])->assertCreated();

    $secondCartId = (int) $secondAddResponse->json('data.id');

    expect($secondCartId)->not->toBe($firstCartId);
    expect($firstAddResponse->json('data.storeId'))->toBe($firstStore->id);
    expect($secondAddResponse->json('data.storeId'))->toBe($secondStore->id);
    expect($secondAddResponse->json('data.merchant.id'))->toBe($secondStore->id);

    $this->assertArrayNotHasKey('merchantGroups', $secondAddResponse->json('data'));
    $this->assertArrayNotHasKey('isMultiMerchant', $secondAddResponse->json('data'));
    $this->assertArrayNotHasKey('checkout', $secondAddResponse->json('data'));

    $this->getJson('/api/v1/user/supermarket/carts')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->assertDatabaseCount('sm_carts', 2);

    $this->assertDatabaseHas('sm_carts', [
        'id' => $firstCartId,
        'user_id' => $user->id,
        'store_id' => $firstStore->id,
    ]);
    $this->assertDatabaseHas('sm_carts', [
        'id' => $secondCartId,
        'user_id' => $user->id,
        'store_id' => $secondStore->id,
    ]);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $firstProduct->id,
        'quantity' => 1,
    ]);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $secondCartId,
        'product_id' => $secondProduct->id,
        'quantity' => 2,
    ]);
});

it('increments quantity when the same supermarket product is added again', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
        'price' => 10,
    ]);

    $firstAddResponse = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $cartId = (int) $firstAddResponse->json('data.id');

    $secondAddResponse = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    expect($secondAddResponse->json('data.id'))->toBe($cartId);
    expect($secondAddResponse->json('data.storeId'))->toBe($store->id);
    expect($secondAddResponse->json('data.items'))->toHaveCount(1);
    expect($secondAddResponse->json('data.items.0.quantity'))->toBe(3);
    expect($secondAddResponse->json('data.productsCount'))->toBe(3);

    $this->assertArrayNotHasKey('merchantGroups', $secondAddResponse->json('data'));
    $this->assertArrayNotHasKey('isMultiMerchant', $secondAddResponse->json('data'));
    $this->assertArrayNotHasKey('checkout', $secondAddResponse->json('data'));

    $this->assertDatabaseCount('sm_carts', 1);
    $this->assertDatabaseCount('sm_cart_items', 1);
    $this->assertDatabaseHas('sm_carts', [
        'id' => $cartId,
        'user_id' => $user->id,
        'store_id' => $store->id,
    ]);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $cartId,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);
});

it('deletes a full supermarket cart by id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
        'price' => 10,
    ]);

    $addResponse = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $cartId = (int) $addResponse->json('data.id');

    $this->deleteJson("/api/v1/user/supermarket/carts/{$cartId}")
        ->assertOk()
        ->assertJsonPath('data.id', null)
        ->assertJsonPath('data.storeId', $store->id)
        ->assertJsonPath('data.productsCount', 0);

    $this->assertDatabaseMissing('sm_carts', [
        'id' => $cartId,
    ]);
    $this->assertDatabaseMissing('sm_cart_items', [
        'cart_id' => $cartId,
    ]);
});
