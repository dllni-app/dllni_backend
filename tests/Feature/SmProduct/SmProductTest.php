<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists products', function (): void {
    SmProductFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-products?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a product', function (): void {
    $product = SmProductFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-products/{$product->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($product->id);
});

it('creates a product', function (): void {
    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $payload = [
        'storeId' => $store->id,
        'categoryId' => $category->id,
        'name' => 'Test Product',
        'sourceType' => 'manual',
        'price' => 9.99,
        'stockQuantity' => 100,
        'lowStockThreshold' => 10,
    ];

    $response = $this->postJson('/api/v1/sm-products', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_products', [
        'name' => 'Test Product',
        'store_id' => $store->id,
    ]);
});

it('updates a product', function (): void {
    $product = SmProductFactory::new()->create(['name' => 'Old Name']);

    $payload = ['name' => 'New Name'];

    $response = $this->putJson("/api/v1/sm-products/{$product->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_products', [
        'id' => $product->id,
        'name' => 'New Name',
    ]);
});

it('deletes a product', function (): void {
    $product = SmProductFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-products/{$product->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_products', ['id' => $product->id]);
});

it('filters by low stock', function (): void {
    SmProductFactory::new()->create(['stock_quantity' => 5, 'low_stock_threshold' => 10]);
    SmProductFactory::new()->create(['stock_quantity' => 100, 'low_stock_threshold' => 10]);

    $response = $this->getJson('/api/v1/sm-products?filter[lowStock]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});
