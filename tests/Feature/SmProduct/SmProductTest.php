<?php

declare(strict_types=1);

use Database\Factories\MasterProductFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Supermarket\Models\SmProduct;

beforeEach(function (): void {
    $context = actingAsSupermarketSeller();
    $this->user = $context->user;
    $this->store = $context->store;
});

it('lists products', function (): void {
    SmProductFactory::new()->count(3)->create(['store_id' => $this->store->id]);
    SmProductFactory::new()->count(2)->create();

    $response = $this->getJson('/api/v1/sm-products?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a product', function (): void {
    $product = SmProductFactory::new()->create(['store_id' => $this->store->id]);

    $response = $this->getJson("/api/v1/sm-products/{$product->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($product->id);
});

it('creates a product', function (): void {
    $category = SmCategoryFactory::new()->create(['store_id' => $this->store->id]);

    $payload = [
        'storeId' => $this->store->id,
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
        'store_id' => $this->store->id,
    ]);
});

it('derives category from master product on create when masterProductId is provided', function (): void {
    $masterProduct = MasterProductFactory::new()->create([
        'name' => 'Olive Oil',
    ]);

    $payload = [
        'storeId' => $this->store->id,
        'masterProductId' => $masterProduct->id,
        'name' => 'Olive Oil',
        'sourceType' => 'catalog_search',
        'price' => 9.99,
        'stockQuantity' => 100,
        'lowStockThreshold' => 10,
    ];

    $response = $this->postJson('/api/v1/sm-products', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_categories', [
        'store_id' => $this->store->id,
        'slug' => 'master-product-' . $masterProduct->id,
        'name' => 'Olive Oil',
    ]);

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $this->store->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Olive Oil',
    ]);
});

it('updates a product', function (): void {
    $product = SmProductFactory::new()->create(['store_id' => $this->store->id, 'name' => 'Old Name']);

    $payload = ['name' => 'New Name'];

    $response = $this->putJson("/api/v1/sm-products/{$product->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_products', [
        'id' => $product->id,
        'name' => 'New Name',
    ]);
});

it('deletes a product', function (): void {
    $product = SmProductFactory::new()->create(['store_id' => $this->store->id]);

    $response = $this->deleteJson("/api/v1/sm-products/{$product->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_products', ['id' => $product->id]);
});

it('filters by low stock', function (): void {
    SmProductFactory::new()->create(['store_id' => $this->store->id, 'stock_quantity' => 5, 'low_stock_threshold' => 10]);
    SmProductFactory::new()->create(['store_id' => $this->store->id, 'stock_quantity' => 100, 'low_stock_threshold' => 10]);

    $response = $this->getJson('/api/v1/sm-products?filter[lowStock]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('returns available products count', function (): void {
    SmProductFactory::new()->count(3)->create(['store_id' => $this->store->id, 'is_available' => true]);
    SmProductFactory::new()->count(2)->create(['store_id' => $this->store->id, 'is_available' => false]);

    $response = $this->getJson('/api/v1/sm-products/available-count');

    $response->assertOk();
    expect($response->json('count'))->toBe(3);
});

it('creates a product with multiple images', function (): void {
    Storage::fake('public');

    $category = SmCategoryFactory::new()->create(['store_id' => $this->store->id]);

    $response = $this->post('/api/v1/sm-products', [
        'storeId' => $this->store->id,
        'categoryId' => $category->id,
        'name' => 'Product With Image',
        'sourceType' => 'manual',
        'price' => 7.25,
        'stockQuantity' => 10,
        'lowStockThreshold' => 2,
        'image' => UploadedFile::fake()->image('cover.jpg'),
        'images' => [
            UploadedFile::fake()->image('gallery-1.jpg'),
            UploadedFile::fake()->image('gallery-2.jpg'),
        ],
    ]);

    $response->assertSuccessful();

    $productId = $response->json('data.id');
    $product = SmProduct::query()->findOrFail($productId);

    expect($product->getMedia(SmProduct::IMAGE_COLLECTION))->toHaveCount(3)
        ->and($response->json('data.imageUrl'))->not->toBeNull()
        ->and($response->json('data.images'))->toHaveCount(3)
        ->and($response->json('data.imageUrls'))->toHaveCount(3);
});

it('replaces product images on update', function (): void {
    Storage::fake('public');

    $product = SmProductFactory::new()->create(['store_id' => $this->store->id]);

    $this->post("/api/v1/sm-products/{$product->id}?_method=PUT", [
        'images' => [
            UploadedFile::fake()->image('first.jpg'),
            UploadedFile::fake()->image('second.jpg'),
        ],
    ])->assertSuccessful();

    $updateResponse = $this->post("/api/v1/sm-products/{$product->id}?_method=PUT", [
        'images' => [
            UploadedFile::fake()->image('third.jpg'),
            UploadedFile::fake()->image('fourth.jpg'),
        ],
    ]);

    $updateResponse->assertSuccessful();

    $product->refresh();

    expect($product->getMedia(SmProduct::IMAGE_COLLECTION))->toHaveCount(2)
        ->and($product->getFirstMedia(SmProduct::IMAGE_COLLECTION)?->file_name)->toContain('third')
        ->and($updateResponse->json('data.images'))->toHaveCount(2)
        ->and($updateResponse->json('data.imageUrls'))->toHaveCount(2);
});

it('imports products from csv with required columns', function (): void {
    if (! class_exists(Rap2hpoutre\FastExcel\FastExcel::class)) {
        $this->markTestSkipped('FastExcel is not installed.');
    }

    $category = SmCategoryFactory::new()->create(['store_id' => $this->store->id]);

    $csv = <<<'CSV'
name,description,image
Apple,Fresh and crispy,
Bread,Daily baked,
CSV;

    $response = $this->post('/api/v1/sm-products/import', [
        'storeId' => $this->store->id,
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
        'store_id' => $this->store->id,
        'category_id' => $category->id,
        'name' => 'Apple',
        'source_type' => 'bulk_import',
    ]);
});

it('imports products without categoryId by creating default store category', function (): void {
    if (! class_exists(Rap2hpoutre\FastExcel\FastExcel::class)) {
        $this->markTestSkipped('FastExcel is not installed.');
    }

    $csv = <<<'CSV'
name,description,image
Apple,Fresh and crispy,
CSV;

    $response = $this->post('/api/v1/sm-products/import', [
        'storeId' => $this->store->id,
        'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertCreated()
        ->assertJsonPath('totalRows', 1)
        ->assertJsonPath('importedCount', 1)
        ->assertJsonPath('failedRows', []);

    $this->assertDatabaseHas('sm_categories', [
        'store_id' => $this->store->id,
        'slug' => 'default-products',
        'name' => 'Default Products',
    ]);

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $this->store->id,
        'name' => 'Apple',
        'source_type' => 'bulk_import',
    ]);
});

it('validates required import columns for csv upload', function (): void {
    if (! class_exists(Rap2hpoutre\FastExcel\FastExcel::class)) {
        $this->markTestSkipped('FastExcel is not installed.');
    }

    $category = SmCategoryFactory::new()->create(['store_id' => $this->store->id]);

    $csv = <<<'CSV'
name,description
Apple,Missing image column
CSV;

    $response = $this->post('/api/v1/sm-products/import', [
        'storeId' => $this->store->id,
        'categoryId' => $category->id,
        'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Missing required column(s): image.');
});
