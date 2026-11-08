<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

it('increments restaurant cart revisions and rejects stale add, update and deletion', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'price' => 500, 'is_available' => true]);

    $added = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id, 'quantity' => 1,
    ])->assertCreated();
    $cartId = (int) $added->json('cartId');
    $itemId = (int) $added->json('itemId');
    $rev1 = (int) $added->json('data.revision');
    expect($rev1)->toBeGreaterThan(0);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id, 'quantity' => 1, 'expectedRevision' => $rev1 - 1,
    ])->assertStatus(409);

    $addedAgain = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id, 'quantity' => 1, 'expectedRevision' => $rev1,
    ])->assertOk();
    $rev2 = (int) $addedAgain->json('data.revision');
    expect($rev2)->toBeGreaterThan($rev1);

    $this->patchJson("/api/v1/user/restaurants/carts/{$cartId}/items/{$itemId}", [
        'quantity' => 4, 'expectedRevision' => $rev1,
    ])->assertStatus(409);

    $updated = $this->patchJson("/api/v1/user/restaurants/carts/{$cartId}/items/{$itemId}", [
        'quantity' => 4, 'expectedRevision' => $rev2,
    ])->assertOk();
    $rev3 = (int) $updated->json('data.revision');
    expect($rev3)->toBeGreaterThan($rev2)
        ->and((int) $updated->json('data.items.0.quantity'))->toBe(4);

    $read = $this->getJson("/api/v1/user/restaurants/carts/{$cartId}")->assertOk();
    expect((int) $read->json('data.revision'))->toBe($rev3);

    $this->deleteJson("/api/v1/user/restaurants/carts/{$cartId}/items/{$itemId}", [
        'expectedRevision' => $rev2,
    ])->assertStatus(409);
    $this->deleteJson("/api/v1/user/restaurants/carts/{$cartId}/items/{$itemId}", [
        'expectedRevision' => $rev3,
    ])->assertOk()->assertJsonPath('data.id', null);
});

it('increments supermarket cart revisions without mixing stores or accepting stale writes', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $store = SmStore::factory()->create();
    $product = SmProduct::factory()->create([
        'store_id' => $store->id, 'price' => 750, 'stock_quantity' => 8, 'is_available' => true,
    ]);
    $added = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id, 'quantity' => 1,
    ])->assertCreated();
    $cartId = (int) $added->json('data.id');
    $itemId = (int) $added->json('data.items.0.id');
    $rev1 = (int) $added->json('data.revision');
    expect($rev1)->toBeGreaterThan(0);

    $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id, 'quantity' => 1, 'expectedRevision' => $rev1 - 1,
    ])->assertStatus(409);
    $addedAgain = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id, 'quantity' => 1, 'expectedRevision' => $rev1,
    ])->assertCreated();
    $rev2 = (int) $addedAgain->json('data.revision');
    expect($rev2)->toBeGreaterThan($rev1);

    $this->patchJson("/api/v1/user/supermarket/carts/{$cartId}/items/{$itemId}", [
        'quantity' => 3, 'expectedRevision' => $rev1,
    ])->assertStatus(409);
    $updated = $this->patchJson("/api/v1/user/supermarket/carts/{$cartId}/items/{$itemId}", [
        'quantity' => 3, 'expectedRevision' => $rev2,
    ])->assertOk();
    $rev3 = (int) $updated->json('data.revision');
    expect($rev3)->toBeGreaterThan($rev2)
        ->and((int) $updated->json('data.items.0.quantity'))->toBe(3);

    $this->deleteJson("/api/v1/user/supermarket/carts/{$cartId}/items/{$itemId}", [
        'expectedRevision' => $rev2,
    ])->assertStatus(409);
    $this->deleteJson("/api/v1/user/supermarket/carts/{$cartId}/items/{$itemId}", [
        'expectedRevision' => $rev3,
    ])->assertOk()->assertJsonPath('data.id', null);
});
