<?php

declare(strict_types=1);

use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;

it('returns luck box options', function (): void {
    $response = $this->getJson('/api/v1/user/supermarket/luck-box/options');

    $response->assertOk()->assertJsonStructure([
        'restrictions',
        'categoryTypes',
    ]);
});

it('returns luck box bundle suggestions', function (): void {
    $store = SmStoreFactory::new()->create([
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    $catA = SmCategoryFactory::new()->create(['store_id' => $store->id, 'name' => 'Snacks']);
    $catB = SmCategoryFactory::new()->create(['store_id' => $store->id, 'name' => 'Drinks']);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $catA->id,
        'name' => 'Rice crackers',
        'price' => 50,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);
    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $catA->id,
        'name' => 'Apple chips',
        'price' => 80,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);
    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $catB->id,
        'name' => 'Juice bottle',
        'price' => 120,
        'stock_quantity' => 10,
        'is_available' => true,
    ]);

    $response = $this->postJson('/api/v1/user/supermarket/luck-box/suggest', [
        'groupSize' => 2,
        'budgetPerPerson' => 150,
        'storeId' => $store->id,
    ]);

    $response->assertOk()->assertJsonStructure([
        'budget' => ['groupSize', 'budgetPerPerson', 'total'],
        'bundles' => [
            [
                'label',
                'labelAr',
                'store' => ['id', 'name'],
                'totalProducts',
                'itemsDescription',
                'totalPrice',
                'estimatedMinutes',
                'lineItems' => [
                    [
                        'productId',
                        'name',
                        'quantity',
                        'unitPrice',
                        'lineTotal',
                        'imageUrl',
                    ],
                ],
            ],
        ],
    ]);

    expect($response->json('bundles'))->not->toBeEmpty();
});
