<?php

declare(strict_types=1);

use Database\Factories\SmCategoryFactory;
use Database\Factories\SmStoreFactory;

beforeEach(function (): void {
    $context = actingAsSupermarketSeller();
    $this->user = $context->user;
    $this->store = $context->store;
});

it('rejects duplicate slugs within same store', function (): void {
    SmCategoryFactory::new()->create([
        'store_id' => $this->store->id,
        'slug' => 'unique-slug',
    ]);

    $payload = [
        'storeId' => $this->store->id,
        'name' => 'Another Category',
        'slug' => 'unique-slug',
    ];

    $response = $this->postJson('/api/v1/sm-categories', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['slug']);
});

it('creates categories on the authenticated owner store regardless of submitted store id', function (): void {
    $otherStore = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $otherStore->id,
        'name' => 'Scoped Category',
        'slug' => 'scoped-category',
    ];

    $response = $this->postJson('/api/v1/sm-categories', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_categories', [
        'store_id' => $this->store->id,
        'slug' => 'scoped-category',
    ]);
});
