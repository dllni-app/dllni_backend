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

it('lists categories', function (): void {
    SmCategoryFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-categories?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a category', function (): void {
    $category = SmCategoryFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-categories/{$category->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($category->id);
});

it('creates a category', function (): void {
    $store = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $store->id,
        'name' => 'Fresh Produce',
        'slug' => 'fresh-produce',
        'sortOrder' => 1,
    ];

    $response = $this->postJson('/api/v1/sm-categories', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_categories', [
        'slug' => 'fresh-produce',
        'store_id' => $store->id,
    ]);
});

it('updates a category', function (): void {
    $category = SmCategoryFactory::new()->create([
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
    $category = SmCategoryFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-categories/{$category->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_categories', ['id' => $category->id]);
});
