<?php

declare(strict_types=1);

use Database\Factories\SmCategoryFactory;
use Database\Factories\SmStoreFactory;

beforeEach(function (): void {
    $context = actingAsSupermarketSeller();
    $this->user = $context->user;
    $this->store = $context->store;
});

it('lists categories', function (): void {
    SmCategoryFactory::new()->count(3)->create(['store_id' => $this->store->id]);

    $response = $this->getJson('/api/v1/sm-categories?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a category', function (): void {
    $category = SmCategoryFactory::new()->create(['store_id' => $this->store->id]);

    $response = $this->getJson("/api/v1/sm-categories/{$category->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($category->id);
});

it('creates a category', function (): void {
    $payload = [
        'storeId' => $this->store->id,
        'name' => 'Fresh Produce',
        'slug' => 'fresh-produce',
        'sortOrder' => 1,
    ];

    $response = $this->postJson('/api/v1/sm-categories', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_categories', [
        'slug' => 'fresh-produce',
        'store_id' => $this->store->id,
    ]);
});

it('updates a category', function (): void {
    $category = SmCategoryFactory::new()->create([
        'store_id' => $this->store->id,
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    $payload = [
        'name' => 'New Name',
        'slug' => 'new-name',
    ];

    $response = $this->putJson("/api/v1/sm-categories/{$category->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_categories', [
        'id' => $category->id,
        'name' => 'New Name',
        'slug' => 'new-name',
    ]);
});

it('deletes a category', function (): void {
    $category = SmCategoryFactory::new()->create(['store_id' => $this->store->id]);

    $response = $this->deleteJson("/api/v1/sm-categories/{$category->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_categories', ['id' => $category->id]);
});
