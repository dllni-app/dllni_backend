<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('rejects duplicate slugs within same store', function (): void {
    $store = SmStoreFactory::new()->create();
    SmCategoryFactory::new()->create([
        'store_id' => $store->id,
        'slug' => 'unique-slug',
    ]);

    $payload = [
        'storeId' => $store->id,
        'name' => 'Another Category',
        'slug' => 'unique-slug',
    ];

    $response = $this->postJson('/api/v1/sm-categories', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['slug']);
});

it('allows same slug in different stores', function (): void {
    $store1 = SmStoreFactory::new()->create();
    $store2 = SmStoreFactory::new()->create();

    SmCategoryFactory::new()->create([
        'store_id' => $store1->id,
        'slug' => 'produce',
    ]);

    $payload = [
        'storeId' => $store2->id,
        'name' => 'Produce',
        'slug' => 'produce',
    ];

    $response = $this->postJson('/api/v1/sm-categories', $payload);

    $response->assertCreated();
});

it('rejects invalid store id', function (): void {
    $payload = [
        'storeId' => 999999,
        'name' => 'Test Category',
        'slug' => 'test-category',
    ];

    $response = $this->postJson('/api/v1/sm-categories', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['storeId']);
});
