<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmProduct;

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

it('returns available products count', function (): void {
    SmProductFactory::new()->count(3)->create(['is_available' => true]);
    SmProductFactory::new()->count(2)->create(['is_available' => false]);

    $response = $this->getJson('/api/v1/sm-products/available-count');

    $response->assertOk();
    expect($response->json('count'))->toBe(3);
});

it('creates a product with one image', function (): void {
    Storage::fake('public');

    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $response = $this->post('/api/v1/sm-products', [
        'storeId' => $store->id,
        'categoryId' => $category->id,
        'name' => 'Product With Image',
        'sourceType' => 'manual',
        'price' => 7.25,
        'stockQuantity' => 10,
        'lowStockThreshold' => 2,
        'image' => UploadedFile::fake()->image('product.jpg'),
    ]);

    $response->assertSuccessful();

    $productId = $response->json('data.id');
    $product = SmProduct::query()->findOrFail($productId);

    expect($product->getMedia(SmProduct::IMAGE_COLLECTION))->toHaveCount(1)
        ->and($response->json('data.imageUrl'))->not->toBeNull();
});

it('replaces product image on update', function (): void {
    Storage::fake('public');

    $product = SmProductFactory::new()->create();

    $this->post("/api/v1/sm-products/{$product->id}?_method=PUT", [
        'image' => UploadedFile::fake()->image('first.jpg'),
    ])->assertSuccessful();

    $updateResponse = $this->post("/api/v1/sm-products/{$product->id}?_method=PUT", [
        'image' => UploadedFile::fake()->image('second.jpg'),
    ]);

    $updateResponse->assertSuccessful();

    $product->refresh();

    expect($product->getMedia(SmProduct::IMAGE_COLLECTION))->toHaveCount(1)
        ->and($product->getFirstMedia(SmProduct::IMAGE_COLLECTION)?->file_name)->toContain('second');
});

it('imports products from csv with required columns', function (): void {
    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $csv = <<<'CSV'
name,description,image
Apple,Fresh and crispy,
Bread,Daily baked,
CSV;

    $response = $this->post('/api/v1/sm-products/import', [
        'storeId' => $store->id,
        'categoryId' => $category->id,
        'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertCreated()
        ->assertJsonPath('totalRows', 2)
        ->assertJsonPath('importedCount', 2)
        ->assertJsonPath('failedRows', []);

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Apple',
        'source_type' => 'bulk_import',
    ]);
});

it('validates required import columns for csv upload', function (): void {
    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $csv = <<<'CSV'
name,description
Apple,Missing image column
CSV;

    $response = $this->post('/api/v1/sm-products/import', [
        'storeId' => $store->id,
        'categoryId' => $category->id,
        'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Missing required column(s): image.');
});
