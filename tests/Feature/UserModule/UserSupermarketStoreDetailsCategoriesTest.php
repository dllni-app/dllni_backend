<?php

declare(strict_types=1);

use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Modules\Supermarket\Models\SmStore;

it('returns product previews from every active supermarket category', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $firstCategory = SmCategoryFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'First Category',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $secondCategory = SmCategoryFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Second Category',
        'sort_order' => 2,
        'is_active' => true,
    ]);

    $inactiveCategory = SmCategoryFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Inactive Category',
        'sort_order' => 3,
        'is_active' => false,
    ]);

    SmProductFactory::new()->count(5)->create([
        'store_id' => $store->id,
        'category_id' => $firstCategory->id,
        'is_available' => true,
    ]);

    SmProductFactory::new()->count(5)->create([
        'store_id' => $store->id,
        'category_id' => $secondCategory->id,
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $inactiveCategory->id,
        'is_available' => true,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/stores/{$store->id}");

    $response->assertOk();

    $categories = collect($response->json('store.categories'));
    $products = collect($response->json('store.products'));

    expect($categories->pluck('id')->all())
        ->toBe([$firstCategory->id, $secondCategory->id]);

    expect($categories->firstWhere('id', $firstCategory->id)['productsCount'])->toBe(5)
        ->and($categories->firstWhere('id', $secondCategory->id)['productsCount'])->toBe(5);

    expect($products->where('categoryId', $firstCategory->id))->toHaveCount(4)
        ->and($products->where('categoryId', $secondCategory->id))->toHaveCount(4)
        ->and($products->where('categoryId', $inactiveCategory->id))->toHaveCount(0);
});
