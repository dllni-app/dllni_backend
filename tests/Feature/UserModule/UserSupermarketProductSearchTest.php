<?php

declare(strict_types=1);

use Database\Factories\SmProductFactory;
use Modules\Supermarket\Models\SmStore;

it('lists supermarket products with pagination', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    SmProductFactory::new()->count(3)->create([
        'store_id' => $store->id,
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search');

    $response->assertOk()->assertJsonStructure([
        'data',
        'links',
        'meta',
    ]);
});

it('filters supermarket products by search query param', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Fresh Bread',
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Chocolate Milk',
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search=bread');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Fresh Bread');
    expect($names)->not->toContain('Chocolate Milk');
});

it('excludes products from unavailable inventory or inactive stores', function (): void {
    $activeStore = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $inactiveStore = SmStore::factory()->create([
        'is_active' => false,
        'suspension_until' => null,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $activeStore->id,
        'name' => 'Visible Product',
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $activeStore->id,
        'name' => 'Unavailable Product',
        'is_available' => false,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $inactiveStore->id,
        'name' => 'Inactive Store Product',
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Visible Product');
    expect($names)->not->toContain('Unavailable Product');
    expect($names)->not->toContain('Inactive Store Product');
});
