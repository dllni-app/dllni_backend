<?php

declare(strict_types=1);

use Database\Factories\MasterProductFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Modules\Supermarket\Database\Seeders\SmProductMasterLinkSeeder;
use Modules\Supermarket\Models\SmStore;

it('compares supermarket products by master product across active stores', function (): void {
    $masterProduct = MasterProductFactory::new()->create([
        'name' => 'Fresh Milk',
    ]);
    $otherMasterProduct = MasterProductFactory::new()->create([
        'name' => 'Chocolate Milk',
    ]);

    $selectedStore = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);
    $cheaperStore = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);
    $expensiveStore = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);
    $inactiveStore = SmStore::factory()->create([
        'is_active' => false,
        'suspension_until' => null,
    ]);

    $selectedCategory = SmCategoryFactory::new()->create(['store_id' => $selectedStore->id]);
    $cheaperCategory = SmCategoryFactory::new()->create(['store_id' => $cheaperStore->id]);
    $expensiveCategory = SmCategoryFactory::new()->create(['store_id' => $expensiveStore->id]);
    $inactiveCategory = SmCategoryFactory::new()->create(['store_id' => $inactiveStore->id]);

    $selectedProduct = SmProductFactory::new()->create([
        'store_id' => $selectedStore->id,
        'category_id' => $selectedCategory->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Store A Full Fat Milk 1L',
        'price' => 15,
        'discounted_price' => null,
        'is_available' => true,
    ]);

    $cheaperProduct = SmProductFactory::new()->create([
        'store_id' => $cheaperStore->id,
        'category_id' => $cheaperCategory->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Different Store Milk Label',
        'price' => 10,
        'discounted_price' => null,
        'is_available' => true,
    ]);

    $expensiveProduct = SmProductFactory::new()->create([
        'store_id' => $expensiveStore->id,
        'category_id' => $expensiveCategory->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Premium Milk Listing',
        'price' => 20,
        'discounted_price' => null,
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $cheaperStore->id,
        'category_id' => $cheaperCategory->id,
        'master_product_id' => $otherMasterProduct->id,
        'name' => 'Store A Full Fat Milk 1L',
        'price' => 5,
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $inactiveStore->id,
        'category_id' => $inactiveCategory->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Inactive Store Milk',
        'price' => 1,
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $cheaperStore->id,
        'category_id' => $cheaperCategory->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Unavailable Milk',
        'price' => 2,
        'is_available' => false,
    ]);

    $response = $this->getJson(
        "/api/v1/user/supermarket/products/{$selectedProduct->id}/compare?page=1&per_page=10"
    );

    $response->assertOk()
        ->assertJsonPath('meta.per_page', 10);

    $ids = collect($response->json('data'))->pluck('id')->values()->all();

    expect($ids)->toBe([$cheaperProduct->id, $expensiveProduct->id]);
});

it('links seeded supermarket products to the correct shared master product', function (): void {
    $existingMasterProduct = MasterProductFactory::new()->create([
        'name' => 'حليب كامل الدسم',
        'unit' => 'liter',
    ]);

    $firstStore = SmStore::factory()->create();
    $secondStore = SmStore::factory()->create();
    $firstCategory = SmCategoryFactory::new()->create(['store_id' => $firstStore->id]);
    $secondCategory = SmCategoryFactory::new()->create(['store_id' => $secondStore->id]);

    $firstMilk = SmProductFactory::new()->create([
        'store_id' => $firstStore->id,
        'category_id' => $firstCategory->id,
        'master_product_id' => null,
        'name' => 'حليب كامل الدسم 1 لتر',
    ]);

    $secondMilk = SmProductFactory::new()->create([
        'store_id' => $secondStore->id,
        'category_id' => $secondCategory->id,
        'master_product_id' => null,
        'name' => 'حليب كامل الدسم 1 لتر',
    ]);

    $uniqueProduct = SmProductFactory::new()->create([
        'store_id' => $firstStore->id,
        'category_id' => $firstCategory->id,
        'master_product_id' => null,
        'name' => 'منتج تجريبي 500 غ',
    ]);

    $this->seed(SmProductMasterLinkSeeder::class);

    $firstMilk->refresh();
    $secondMilk->refresh();
    $uniqueProduct->refresh();

    expect($firstMilk->master_product_id)->toBe($existingMasterProduct->id)
        ->and($secondMilk->master_product_id)->toBe($existingMasterProduct->id)
        ->and($uniqueProduct->master_product_id)->not->toBeNull();
});
