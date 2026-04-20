<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\MasterProductFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmStoreFactory;
use Database\Seeders\MasterProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($this->owner);

    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->owner->id,
    ]);

    $this->category = SmCategoryFactory::new()->create([
        'store_id' => $this->store->id,
    ]);
});

it('searches active master products by name prefix', function (): void {
    MasterProductFactory::new()->create(['name' => 'Soap', 'barcode' => '7890000000001', 'is_active' => true]);
    MasterProductFactory::new()->create(['name' => 'Sesame Oil', 'barcode' => '7890000000002', 'is_active' => true]);
    MasterProductFactory::new()->create(['name' => 'Tea', 'barcode' => '7890000000003', 'is_active' => true]);
    MasterProductFactory::new()->create(['name' => 'Sealant', 'barcode' => '7890000000004', 'is_active' => false]);

    $response = $this->getJson('/api/v1/store-owner/master-products/search?index=se');

    $response->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Sesame Oil');
    expect($names)->not->toContain('Soap');
    expect($names)->not->toContain('Tea');
    expect($names)->not->toContain('Sealant');

    $response->assertJsonStructure([
        'data' => [
            [
                'id',
                'masterProductId',
                'name',
                'barcode',
            ],
        ],
    ]);
});

it('searches active master products by barcode prefix', function (): void {
    MasterProductFactory::new()->create(['name' => 'Orange Juice', 'barcode' => '5551230000001', 'is_active' => true]);
    MasterProductFactory::new()->create(['name' => 'Apple Juice', 'barcode' => '4441230000001', 'is_active' => true]);

    $response = $this->getJson('/api/v1/store-owner/master-products/search?index=555');

    $response->assertOk();

    $barcodes = collect($response->json('data'))->pluck('barcode')->all();

    expect($barcodes)->toContain('5551230000001');
    expect($barcodes)->not->toContain('4441230000001');
});

it('returns seeded arabic bread master products on store-owner search', function (): void {
    $this->seed(MasterProductSeeder::class);

    $response = $this->getJson('/api/v1/store-owner/master-products/search?index=خبز&page=1&perPage=10');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 6);

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('خبز عربي أبيض');
    expect($names)->toContain('خبز قمح كامل');
    expect($names)->toContain('خبز توست أبيض');

    $masterProduct = \App\Models\MasterProduct::query()->where('name', 'خبز عربي أبيض')->firstOrFail();

    expect($masterProduct->getFirstMedia(\App\Models\MasterProduct::IMAGE_COLLECTION))->not->toBeNull();
});

it('creates store products from master products and links master_product_id', function (): void {
    $firstMasterProduct = MasterProductFactory::new()->create([
        'name' => 'Sparkling Water',
        'barcode' => '1234567890123',
        'description' => 'Natural sparkling water',
        'is_active' => true,
    ]);

    $secondMasterProduct = MasterProductFactory::new()->create([
        'name' => 'Mineral Water',
        'barcode' => '1234567890999',
        'description' => 'Natural mineral water',
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [$firstMasterProduct->id, $secondMasterProduct->id],
    ]);

    $response->assertCreated();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.masterProductId', $firstMasterProduct->id);
    $response->assertJsonPath('data.0.name', 'Sparkling Water');
    $response->assertJsonPath('data.0.barcode', '1234567890123');
    $response->assertJsonPath('data.0.stockQuantity', 0);
    $response->assertJsonPath('data.0.price', 0);
    $response->assertJsonPath('data.0.discountedPrice', 0);
    $response->assertJsonPath('data.0.lowStockThreshold', 0);
    $response->assertJsonPath('data.0.isAvailable', true);
    $response->assertJsonPath('data.1.masterProductId', $secondMasterProduct->id);
    $response->assertJsonPath('data.1.name', 'Mineral Water');
    $response->assertJsonPath('data.1.barcode', '1234567890999');
    $response->assertJsonPath('data.1.stockQuantity', 0);

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $this->store->id,
        'category_id' => $this->category->id,
        'master_product_id' => $firstMasterProduct->id,
        'name' => 'Sparkling Water',
        'barcode' => '1234567890123',
        'price' => '0.00',
        'discounted_price' => '0.00',
        'stock_quantity' => 0,
        'low_stock_threshold' => 0,
        'is_available' => true,
    ]);

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $this->store->id,
        'category_id' => $this->category->id,
        'master_product_id' => $secondMasterProduct->id,
        'name' => 'Mineral Water',
        'barcode' => '1234567890999',
        'price' => '0.00',
        'discounted_price' => '0.00',
        'stock_quantity' => 0,
        'low_stock_threshold' => 0,
        'is_available' => true,
    ]);
});

it('fails when owner has no store', function (): void {
    \Modules\Supermarket\Models\SmStore::query()->where('id', $this->store->id)->delete();
    $masterProduct = MasterProductFactory::new()->create(['is_active' => true]);

    $response = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [$masterProduct->id],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['store']);
});

it('creates product from single master product id and fills defaults', function (): void {
    $masterProduct = MasterProductFactory::new()->create([
        'name' => 'Greek Yogurt',
        'barcode' => '9991112223334',
        'description' => 'High protein yogurt',
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [$masterProduct->id],
    ]);

    $response->assertCreated();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.masterProductId', $masterProduct->id);
    $response->assertJsonPath('data.0.name', 'Greek Yogurt');
    $response->assertJsonPath('data.0.barcode', '9991112223334');
    $response->assertJsonPath('data.0.description', 'High protein yogurt');
    $response->assertJsonPath('data.0.stockQuantity', 0);
    $response->assertJsonPath('data.0.price', 0);
    $response->assertJsonPath('data.0.discountedPrice', 0);
    $response->assertJsonPath('data.0.categoryId', $this->category->id);
    $response->assertJsonPath('data.0.storeId', $this->store->id);
    expect($response->json('data.0.expiresAt'))->not->toBeNull();

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $this->store->id,
        'category_id' => $this->category->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Greek Yogurt',
        'barcode' => '9991112223334',
        'description' => 'High protein yogurt',
        'price' => '0.00',
        'discounted_price' => '0.00',
        'stock_quantity' => 0,
        'low_stock_threshold' => 0,
        'is_available' => true,
    ]);
});

it('validates master product ids input contract', function (): void {
    $masterProduct = MasterProductFactory::new()->create(['is_active' => true]);

    $emptyResponse = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [],
    ]);

    $emptyResponse->assertStatus(422);
    $emptyResponse->assertJsonValidationErrors(['masterProductIds']);

    $duplicateResponse = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [$masterProduct->id, $masterProduct->id],
    ]);

    $duplicateResponse->assertStatus(422);
    $duplicateResponse->assertJsonValidationErrors(['masterProductIds.1']);

    $invalidResponse = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [999999],
    ]);

    $invalidResponse->assertStatus(422);
    $invalidResponse->assertJsonValidationErrors(['masterProductIds.0']);
});

it('fails when selected owner store has no categories', function (): void {
    \Modules\Supermarket\Models\SmCategory::query()->where('id', $this->category->id)->delete();
    $masterProduct = MasterProductFactory::new()->create(['is_active' => true]);

    $response = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [$masterProduct->id],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['category']);
});

it('fails when requested master product is inactive', function (): void {
    $inactiveMasterProduct = MasterProductFactory::new()->create(['is_active' => false]);

    $response = $this->postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [$inactiveMasterProduct->id],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['masterProductIds']);
});
