<?php

declare(strict_types=1);

use Database\Factories\SmProductFactory;
use Illuminate\Support\Facades\Http;
use Modules\Supermarket\Models\SmStore;

beforeEach(function (): void {
    config()->set('services.dallelni_search.auth_token', 'dallelni-ai');
    config()->set('services.dallelni_search.products_base_url', 'https://dallelni.karriya.ai/products');
});

it('keeps a close English transposition typo match returned by semantic search', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $milk = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Fresh Milk',
        'description' => 'Full fat fresh milk',
        'is_available' => true,
    ]);

    $bread = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Fresh Bread',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'mlik',
            'results' => [
                [
                    'product_id' => $milk->id,
                    'score' => 0.84,
                ],
                [
                    'product_id' => $bread->id,
                    'score' => 0.83,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search=mlik');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($milk->id);
    expect($ids)->not->toContain($bread->id);
});

it('keeps an English missing-character typo match', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $chocolate = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Chocolate Milk',
        'description' => 'Chocolate flavored milk',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'choclate',
            'results' => [
                [
                    'product_id' => $chocolate->id,
                    'score' => 0.82,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search=choclate');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($chocolate->id);
});

it('keeps an English extra-character typo match', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $tomatoes = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Fresh Tomatoes',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'tomatoess',
            'results' => [
                [
                    'product_id' => $tomatoes->id,
                    'score' => 0.81,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search=tomatoess');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($tomatoes->id);
});

it('matches English text case-insensitively', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $coffee = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Coffee Beans',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'COFFEE',
            'results' => [
                [
                    'product_id' => $coffee->id,
                    'score' => 0.84,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search=COFFEE');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($coffee->id);
});

it('matches common English accent variants', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $cafe = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Café Coffee',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'cafe',
            'results' => [
                [
                    'product_id' => $cafe->id,
                    'score' => 0.84,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search=cafe');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($cafe->id);
});
