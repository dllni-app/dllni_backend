<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

it('keeps a single active supermarket cart per user when store changes', function (): void {
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

    $secondAddResponse->assertJsonPath('data.id', $firstCartId);
    $secondAddResponse->assertJsonPath('data.merchant.id', $secondStore->id);

    $this->assertDatabaseCount('sm_carts', 1);
    $this->assertDatabaseHas('sm_carts', [
        'id' => $firstCartId,
        'user_id' => $user->id,
        'store_id' => $secondStore->id,
    ]);
    $this->assertDatabaseMissing('sm_cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $firstProduct->id,
    ]);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $secondProduct->id,
        'quantity' => 2,
    ]);
});
