<?php

declare(strict_types=1);

use Database\Factories\SmProductFactory;
use Illuminate\Support\Facades\Http;
use Modules\Supermarket\Models\SmStore;

beforeEach(function (): void {
    config()->set('services.dallelni_search.auth_token', 'dallelni-ai');
    config()->set('services.dallelni_search.products_base_url', 'https://dallelni.karriya.ai/products');
});

it('keeps a close Arabic typo match returned by semantic search', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $lentils = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'عدس أحمر',
        'description' => 'عدس أحمر حب',
        'is_available' => true,
    ]);

    $juice = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'عصير برتقال',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'عذس',
            'results' => [
                [
                    'product_id' => $lentils->id,
                    'score' => 0.84,
                ],
                [
                    'product_id' => $juice->id,
                    'score' => 0.83,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search='.urlencode('عذس'));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($lentils->id);
    expect($ids)->not->toContain($juice->id);
});

it('normalizes common Arabic character variants before validating semantic results', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $rice = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'أرز بسمتي',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'ارز',
            'results' => [
                [
                    'product_id' => $rice->id,
                    'score' => 0.84,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search='.urlencode('ارز'));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($rice->id);
});

it('handles Arabic diacritics and Persian character variants', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $milk = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'حليب كامل الدسم',
        'is_available' => true,
    ]);

    Http::fake([
        'https://dallelni.karriya.ai/products/search' => Http::response([
            'query' => 'حَلِيب',
            'results' => [
                [
                    'product_id' => $milk->id,
                    'score' => 0.84,
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search='.urlencode('حَلِيب'));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($milk->id);
});
