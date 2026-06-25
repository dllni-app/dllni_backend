<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

it('preserves items from multiple stores in the same supermarket cart and exposes a primary merchant', function (): void {
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

    expect($secondAddResponse->json('data.id'))->toBe($firstCartId);
    expect($secondAddResponse->json('data.merchantGroups'))->toHaveCount(2);
    expect($secondAddResponse->json('data.merchant.id'))->not->toBeNull();
    expect($secondAddResponse->json('data.isMultiMerchant'))->toBeTrue();
    expect($secondAddResponse->json('data.checkout.canPlaceOrder'))->toBeFalse();
    expect($secondAddResponse->json('data.checkout.blockedReason'))->toBe('mixed_supermarket_cart');

    $this->assertDatabaseCount('sm_carts', 1);

    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $firstProduct->id,
        'quantity' => 1,
    ]);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $firstCartId,
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
    expect($secondAddResponse->json('data.items'))->toHaveCount(1);
    expect($secondAddResponse->json('data.items.0.quantity'))->toBe(3);
    expect($secondAddResponse->json('data.productsCount'))->toBe(3);
    expect($secondAddResponse->json('data.isMultiMerchant'))->toBeFalse();
    expect($secondAddResponse->json('data.checkout.canPlaceOrder'))->toBeTrue();

    $this->assertDatabaseCount('sm_carts', 1);
    $this->assertDatabaseCount('sm_cart_items', 1);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $cartId,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);
});
