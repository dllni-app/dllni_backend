<?php

declare(strict_types=1);

use Database\Factories\SmCategoryFactory;
use Database\Factories\SmStoreFactory;

beforeEach(function (): void {
    $context = actingAsSupermarketSeller();
    $this->user = $context->user;
    $this->store = $context->store;
});

it('filters by store id', function (): void {
    $store2 = SmStoreFactory::new()->create();

    $category1 = SmCategoryFactory::new()->create(['store_id' => $this->store->id]);
    SmCategoryFactory::new()->create(['store_id' => $store2->id]);

    $response = $this->getJson("/api/v1/sm-categories?filter[storeId]={$this->store->id}");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($category1->id);
});

it('filters by active flag', function (): void {
    $activeCategory = SmCategoryFactory::new()->create(['store_id' => $this->store->id, 'is_active' => true]);
    SmCategoryFactory::new()->create(['store_id' => $this->store->id, 'is_active' => false]);

    $response = $this->getJson('/api/v1/sm-categories?filter[isActive]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($activeCategory->id);
});

it('filters by search term', function (): void {
    $matchedCategory = SmCategoryFactory::new()->create(['store_id' => $this->store->id, 'name' => 'Fresh Produce']);
    SmCategoryFactory::new()->create(['store_id' => $this->store->id, 'name' => 'Dairy Products']);

    $response = $this->getJson('/api/v1/sm-categories?filter[search]=Fresh');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($matchedCategory->id);
});

it('sorts by sort order', function (): void {
    $category1 = SmCategoryFactory::new()->create(['store_id' => $this->store->id, 'sort_order' => 10]);
    $category2 = SmCategoryFactory::new()->create(['store_id' => $this->store->id, 'sort_order' => 5]);
    $category3 = SmCategoryFactory::new()->create(['store_id' => $this->store->id, 'sort_order' => 15]);

    $response = $this->getJson('/api/v1/sm-categories?sort=sortOrder');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->toBe([$category2->id, $category1->id, $category3->id]);
});
