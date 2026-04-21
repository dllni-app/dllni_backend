<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\MasterProductFactory;
use Database\Factories\SmProductFactory;
use Database\Seeders\MasterProductSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmSmartList;
use Modules\Supermarket\Models\SmStore;

it('requires authentication for shopping lists', function (): void {
    $response = $this->getJson('/api/v1/user/supermarket/shopping-lists');

    $response->assertUnauthorized();
});

it('creates a shopping list and lists it for the authenticated user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $createResponse = $this->postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Home list',
        'description' => 'Weekly basics',
        'isActive' => true,
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.name', 'Home list')
        ->assertJsonPath('data.items', [])
        ->assertJsonMissingPath('data.storeId');

    $listId = (int) $createResponse->json('data.id');

    $indexResponse = $this->getJson('/api/v1/user/supermarket/shopping-lists');

    $indexResponse->assertOk();
    expect($indexResponse->json('data'))->toHaveCount(1)
        ->and($indexResponse->json('data.0.id'))->toBe($listId)
        ->and($indexResponse->json('data.0.itemsCount'))->toBe(0);
});

it('returns shopping list schedules on create and show', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $createResponse = $this->postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Weekly groceries',
        'description' => 'Basics',
        'isActive' => true,
        'schedule' => [
            'isActive' => true,
            'frequencyType' => 'weekly',
            'weekDays' => [1, 4],
            'periods' => [
                [
                    'label' => 'Morning',
                    'fromTime' => '09:00',
                    'toTime' => '11:00',
                ],
            ],
        ],
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.schedule.frequencyType', 'weekly')
        ->assertJsonPath('data.schedule.weekDays', [1, 4])
        ->assertJsonPath('data.schedule.monthDays', [])
        ->assertJsonPath('data.schedule.periods.0.fromTime', '09:00')
        ->assertJsonPath('data.schedule.periods.0.toTime', '11:00');

    $listId = (int) $createResponse->json('data.id');

    $showResponse = $this->getJson("/api/v1/user/supermarket/shopping-lists/{$listId}");

    $showResponse->assertOk()
        ->assertJsonPath('data.schedule.frequencyType', 'weekly')
        ->assertJsonPath('data.schedule.weekDays', [1, 4])
        ->assertJsonPath('data.schedule.periods.0.label', 'Morning')
        ->assertJsonMissingPath('data.storeId');
});

it('returns 404 when accessing another users shopping list', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $list = SmSmartList::create([
        'user_id' => $owner->id,
        'name' => 'Private',
        'description' => null,
        'is_active' => true,
    ]);

    Sanctum::actingAs($other);

    $this->getJson("/api/v1/user/supermarket/shopping-lists/{$list->id}")
        ->assertNotFound();
});

it('adds list items to the supermarket cart for a store', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create();
    $master = MasterProductFactory::new()->create(['name' => 'Labneh']);
    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'master_product_id' => $master->id,
        'name' => 'Labneh 250g',
        'price' => 10,
        'discounted_price' => null,
        'is_available' => true,
    ]);

    $listResponse = $this->postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Reorder list',
    ]);
    $listId = (int) $listResponse->json('data.id');

    $this->postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/items", [
        'masterProductId' => $master->id,
        'quantity' => 2,
    ])->assertCreated();

    $addToCartResponse = $this->postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/add-to-cart", []);

    $addToCartResponse->assertCreated()
        ->assertJsonPath('data.merchantGroups.0.merchant.id', $store->id);

    $items = $addToCartResponse->json('data.merchantGroups.0.items');
    expect($items)->toHaveCount(1)
        ->and($items[0]['quantity'])->toBe(2);
});

it('excludes items marked not included when adding to cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create();
    $master = MasterProductFactory::new()->create();
    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'master_product_id' => $master->id,
        'price' => 5,
        'is_available' => true,
    ]);

    $listId = (int) $this->postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Toggle list',
    ])->json('data.id');

    $this->postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/items", [
        'masterProductId' => $master->id,
        'quantity' => 1,
        'isIncluded' => false,
    ])->assertCreated();

    $this->postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/add-to-cart", [])
        ->assertUnprocessable();
});

it('deletes a shopping list line item', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $master = MasterProductFactory::new()->create();
    $listId = (int) $this->postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Edit list',
    ])->json('data.id');

    $itemId = (int) $this->postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/items", [
        'masterProductId' => $master->id,
        'quantity' => 1,
    ])->json('data.items.0.id');

    $this->deleteJson("/api/v1/user/supermarket/shopping-lists/{$listId}/items/{$itemId}")
        ->assertNoContent();

    $this->getJson("/api/v1/user/supermarket/shopping-lists/{$listId}")
        ->assertOk()
        ->assertJsonPath('data.items', []);
});

it('searches active master products for shopping list picker by name prefix', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MasterProductFactory::new()->create(['name' => 'Sesame Oil', 'is_active' => true]);
    MasterProductFactory::new()->create(['name' => 'Soap', 'is_active' => true]);
    MasterProductFactory::new()->create(['name' => 'Tea', 'is_active' => true]);
    MasterProductFactory::new()->create(['name' => 'Sealant', 'is_active' => false]);

    $nameResponse = $this->getJson('/api/v1/user/supermarket/master-products/search?index=se');

    $nameResponse->assertOk();
    $nameResponse->assertJsonStructure([
        'data' => [
            [
                'id',
                'masterProductId',
                'name',
            ],
        ],
    ]);

    $names = collect($nameResponse->json('data'))->pluck('name')->all();
    expect($names)->toContain('Sesame Oil');
    expect($names)->not->toContain('Soap');
    expect($names)->not->toContain('Tea');
    expect($names)->not->toContain('Sealant');
});

it('returns seeded arabic bread master products for the shopping list picker', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->seed(MasterProductSeeder::class);

    $response = $this->getJson('/api/v1/user/supermarket/master-products/search?index=خبز&page=1&perPage=10');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 6);

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('خبز عربي أبيض');
    expect($names)->toContain('خبز قمح كامل');
    expect($names)->toContain('خبز توست أبيض');

    $masterProduct = \App\Models\MasterProduct::query()->where('name', 'خبز عربي أبيض')->firstOrFail();

    expect($masterProduct->getFirstMedia(\App\Models\MasterProduct::IMAGE_COLLECTION))->not->toBeNull();
});
