<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\MasterProductFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmStoreFactory;
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
        'storeId' => $this->store->id,
        'products' => [
            [
                'categoryId' => $this->category->id,
                'masterProductId' => $firstMasterProduct->id,
                'title' => 'Sparkling Water Premium',
                'price' => 3.75,
                'stockQuantity' => 25,
            ],
            [
                'categoryId' => $this->category->id,
                'masterProductId' => $secondMasterProduct->id,
                'title' => 'Mineral Water Large',
                'price' => 4.25,
                'stockQuantity' => 40,
            ],
        ],
    ]);

    $response->assertCreated();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.masterProductId', $firstMasterProduct->id);
    $response->assertJsonPath('data.0.name', 'Sparkling Water Premium');
    $response->assertJsonPath('data.0.barcode', '1234567890123');
    $response->assertJsonPath('data.0.stockQuantity', 25);
    $response->assertJsonPath('data.1.masterProductId', $secondMasterProduct->id);
    $response->assertJsonPath('data.1.name', 'Mineral Water Large');
    $response->assertJsonPath('data.1.barcode', '1234567890999');
    $response->assertJsonPath('data.1.stockQuantity', 40);

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $this->store->id,
        'category_id' => $this->category->id,
        'master_product_id' => $firstMasterProduct->id,
        'name' => 'Sparkling Water Premium',
        'barcode' => '1234567890123',
        'price' => '3.75',
        'stock_quantity' => 25,
    ]);

    $this->assertDatabaseHas('sm_products', [
        'store_id' => $this->store->id,
        'category_id' => $this->category->id,
        'master_product_id' => $secondMasterProduct->id,
        'name' => 'Mineral Water Large',
        'barcode' => '1234567890999',
        'price' => '4.25',
        'stock_quantity' => 40,
    ]);
});

it('forbids creating product in another owner store', function (): void {
    $anotherOwner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    $anotherStore = SmStoreFactory::new()->create([
        'owner_user_id' => $anotherOwner->id,
    ]);

    $foreignCategory = SmCategoryFactory::new()->create([
        'store_id' => $anotherStore->id,
    ]);

    $masterProduct = MasterProductFactory::new()->create(['is_active' => true]);

    $response = $this->postJson('/api/v1/store-owner/products/from-master', [
        'storeId' => $anotherStore->id,
        'products' => [
            [
                'categoryId' => $foreignCategory->id,
                'masterProductId' => $masterProduct->id,
                'title' => 'Foreign Product',
                'price' => 9.50,
                'stockQuantity' => 10,
            ],
        ],
    ]);

    $response->assertForbidden();
});
