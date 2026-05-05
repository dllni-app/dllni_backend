<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\MasterProductFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Enums\DayOfWeek;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmStore;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

it('USR-SM-01 returns supermarket homepage featured offers', function (): void {
    $response = getJson('/api/v1/user/supermarket/home/featured-offers');

    $response->assertOk()->assertJsonStructure(['offers']);
});

it('USR-SM-02 returns supermarket nearby stores', function (): void {
    $response = getJson('/api/v1/user/supermarket/home/nearby-stores');

    $response->assertOk()->assertJsonStructure(['stores']);
});

it('USR-SM-03 shows supermarket store details', function (): void {
    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $response = getJson("/api/v1/user/supermarket/stores/{$store->id}");

    $response->assertOk()->assertJsonPath('store.id', $store->id);
});

it('USR-SM-04 returns supermarket product compare results', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $selectedProduct = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk',
        'is_available' => true,
    ]);

    $matchingProduct = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk 1L',
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Chocolate Drink',
        'is_available' => true,
    ]);

    $response = getJson("/api/v1/user/supermarket/products/{$selectedProduct->id}/compare?perPage=5");

    $response->assertOk()
        ->assertJsonPath('meta.per_page', 5);

    expect(collect($response->json('data'))->pluck('id')->all())->toContain($matchingProduct->id);
});

it('USR-SM-05 adds and removes supermarket store favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $owner = User::factory()->create();
    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
        'is_active' => true,
    ]);

    $create = postJson("/api/v1/user/favorites/supermarket/stores/{$store->id}");

    $create->assertCreated()
        ->assertJsonPath('store.id', $store->id)
        ->assertJsonPath('store.isFavorited', true);

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(1);

    $delete = $this->deleteJson("/api/v1/user/favorites/supermarket/stores/{$store->id}");

    $delete->assertNoContent();
    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('USR-SM-06 adds and removes supermarket product favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Organic Apples',
        'is_available' => true,
    ]);

    $create = postJson("/api/v1/user/favorites/supermarket/products/{$product->id}");

    $create->assertCreated()
        ->assertJsonPath('product.id', $product->id)
        ->assertJsonPath('product.isFavorite', true);

    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(1);

    $delete = $this->deleteJson("/api/v1/user/favorites/supermarket/products/{$product->id}");

    $delete->assertNoContent();
    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('USR-SM-07 adds updates and deletes a supermarket cart item', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
        'price' => 14,
    ]);

    $create = postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ]);

    $create->assertCreated()
        ->assertJsonPath('data.items.0.quantity', 1);

    $itemId = (int) $create->json('data.items.0.id');

    patchJson("/api/v1/user/supermarket/cart/items/{$itemId}", [
        'quantity' => 3,
    ])->assertOk()->assertJsonPath('data.items.0.quantity', 3);

    $this->deleteJson("/api/v1/user/supermarket/cart/items/{$itemId}")
        ->assertNoContent();

    $cart = getJson('/api/v1/user/supermarket/cart');
    $cart->assertOk()->assertJsonPath('data.items', []);
});

it('USR-SM-08 creates a shopping list and adds it to the supermarket cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => true]);
    $master = MasterProductFactory::new()->create(['name' => 'Labneh']);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'master_product_id' => $master->id,
        'name' => 'Labneh 250g',
        'price' => 10,
        'discounted_price' => null,
        'is_available' => true,
    ]);

    $listResponse = postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Reorder list',
    ]);

    $listResponse->assertCreated();
    $listId = (int) $listResponse->json('data.id');

    postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/items", [
        'masterProductId' => $master->id,
        'quantity' => 2,
    ])->assertCreated();

    $addToCart = postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/add-to-cart", []);

    $addToCart->assertCreated()
        ->assertJsonPath('data.merchantGroups.0.merchant.id', $store->id);
});

it('USR-SM-09 places a supermarket order and clears the cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
        'price' => 12,
    ]);

    postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    $response = postJson('/api/v1/user/supermarket/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'note' => 'Leave at the front desk',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $this->getJson('/api/v1/user/supermarket/cart')
        ->assertOk()
        ->assertJsonPath('data.items', []);
});

it('USR-SM-10 tracks a supermarket order', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = SmOrderFactory::new()->create([
        'customer_id' => $user->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = getJson("/api/v1/user/orders/supermarket/{$order->id}/tracking");

    $response->assertOk()
        ->assertJsonPath('data.order.id', $order->id);
});

it('USR-SM-11 normalizes supermarket product text', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/supermarket/products/normalize-text', [
        'text' => 'I need fresh milk and labneh',
        'module' => 'supermarket',
        'locale' => 'en',
    ]);

    $response->assertOk()->assertJsonStructure(['data']);
});

it('USR-SM-12 shows supermarket product details', function (): void {
    $store = SmStore::factory()->create(['is_active' => true]);
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
        'name' => 'Showcase Product',
    ]);

    $response = getJson("/api/v1/user/supermarket/products/{$product->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('product.id', $product->id)
        ->assertJsonPath('shareUrl', fn(string $value): bool => str_contains($value, (string) $product->id));
});

it('USR-SM-14 rejects unauthenticated supermarket cart access', function (): void {
    $this->getJson('/api/v1/user/supermarket/cart')->assertUnauthorized();
});

it('USR-SM-15 updates and deletes a shopping list', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $listId = (int) postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Weekly list',
    ])->json('data.id');

    patchJson("/api/v1/user/supermarket/shopping-lists/{$listId}", [
        'name' => 'Updated weekly list',
    ])->assertOk()->assertJsonPath('data.name', 'Updated weekly list');

    $this->deleteJson("/api/v1/user/supermarket/shopping-lists/{$listId}")
        ->assertNoContent();
});

it('USR-SM-13 rejects supermarket shopping lists without a common store', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $firstStore = SmStore::factory()->create(['is_active' => true]);
    $secondStore = SmStore::factory()->create(['is_active' => true]);
    $master = MasterProductFactory::new()->create(['name' => 'Mixed Item']);

    SmProductFactory::new()->create([
        'store_id' => $firstStore->id,
        'master_product_id' => $master->id,
        'name' => 'Mixed Item A',
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $secondStore->id,
        'master_product_id' => $master->id,
        'name' => 'Mixed Item B',
        'is_available' => true,
    ]);

    $listId = (int) postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'Cross-store list',
    ])->json('data.id');

    postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/items", [
        'masterProductId' => $master->id,
        'quantity' => 1,
    ])->assertCreated();

    $response = postJson("/api/v1/user/supermarket/shopping-lists/{$listId}/add-to-cart", []);

    $response->assertUnprocessable();
});

it('USR-SM-19 rejects supermarket order placement when cart is empty', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/supermarket/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.cart.0', 'Cart is empty.');
});

it('USR-SM-20 rejects invalid scheduledAt during supermarket order placement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/supermarket/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'scheduled',
        'scheduledAt' => now()->subHour()->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduledAt'))->not->toBeNull();
});

it('OWN-SM-07 rejects literal owner reject path placeholder used by buggy integration', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    Sanctum::actingAs($seller);

    $response = postJson('/api/v1/store-owner/orders/{order}/reject', [
        'reason' => 'Out of stock for this item today',
        'rejectionType' => 'out_of_stock',
    ]);

    $response->assertNotFound();
});

it('OWN-SM-14 rejects stock update with invalid operation value', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
    ]);

    $response = putJson("/api/v1/store-owner/products/{$product->id}/stock", [
        'quantity' => 10,
        'operation' => 'MULTIPLY',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.operation'))->not->toBeNull();
});

it('OWN-SM-24 blocks wrong-role access to store-owner dashboard', function (): void {
    $restaurantSeller = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    Sanctum::actingAs($restaurantSeller);

    $response = getJson('/api/v1/store-owner/dashboard');

    $response->assertForbidden();
});

it('OWN-SM-01 returns supermarket owner dashboard totals', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    SmOrderFactory::new()->count(2)->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 125.50,
    ]);

    $response = getJson('/api/v1/store-owner/dashboard');

    $response->assertOk()
        ->assertJsonPath('data.totalOrders', 3)
        ->assertJsonPath('data.completedOrders', 1)
        ->assertJsonPath('data.newOrders', 2);
});

it('OWN-SM-02 returns supermarket hourly order counts', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $response = getJson('/api/v1/sm-orders/hourly-count');

    $response->assertOk()->assertJsonStructure(['data']);
});

it('OWN-SM-03 lists supermarket order queue entries', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $response = getJson('/api/v1/sm-orders?perPage=10');

    $response->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
});

it('OWN-SM-06 rejects invalid stock quantity values', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
    ]);

    $response = putJson("/api/v1/store-owner/products/{$product->id}/stock", [
        'quantity' => -1,
        'operation' => 'SET',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('OWN-SM-08 returns store owner activity logs', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $response = getJson('/api/v1/store-owner/activity-logs');

    $response->assertOk()->assertJsonStructure(['data']);
});

it('OWN-SM-03 accepts a pending supermarket order', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
        'trust_score' => 100,
    ]);

    $order = SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = postJson("/api/v1/store-owner/orders/{$order->id}/accept");

    $response->assertOk()
        ->assertJsonPath('data.status', 'accepted');
});

it('OWN-SM-04 validates reject reason and rejection type', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $order = SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = postJson("/api/v1/store-owner/orders/{$order->id}/reject", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['reason', 'rejectionType']);
});

it('OWN-SM-05 hands over a ready order to the courier', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $order = SmOrderFactory::new()->readyForPickup()->create([
        'store_id' => $store->id,
        'picked_up_at' => null,
    ]);

    $response = postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover");

    $response->assertOk()
        ->assertJsonPath('data.status', 'picked_up');
});

it('OWN-SM-09 returns inventory summary for the store owner', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'price' => 100,
        'discounted_price' => 80,
        'stock_quantity' => 10,
        'low_stock_threshold' => 10,
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'price' => 50,
        'discounted_price' => null,
        'stock_quantity' => 3,
        'low_stock_threshold' => 2,
        'is_available' => true,
    ]);

    $response = getJson('/api/v1/store-owner/inventory/summary');

    $response->assertSuccessful()
        ->assertJsonPath('data.productSkus', 2)
        ->assertJsonPath('data.lowStockCount', 1);
});

it('OWN-SM-12 updates stock and marks the product as low stock when needed', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'stock_quantity' => 10,
        'low_stock_threshold' => 5,
    ]);

    $response = putJson("/api/v1/store-owner/products/{$product->id}/stock", [
        'quantity' => 4,
        'operation' => 'SET',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.stock_quantity', 4)
        ->assertJsonPath('data.is_low_stock', true);
});

it('OWN-SM-13 updates store operating hours', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
        'is_temporarily_closed' => false,
    ]);

    $response = $this->putJson('/api/v1/store-owner/store/operating-hours', [
        'isTemporarilyClosed' => true,
        'dailyHours' => [
            [
                'dayOfWeek' => DayOfWeek::Monday->value,
                'isEnabled' => true,
                'timeSlots' => [
                    [
                        'startTime' => '09:00 AM',
                        'endTime' => '05:00 PM',
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.isTemporarilyClosed', true)
        ->assertJsonPath('data.dailyHours.0.dayOfWeek', DayOfWeek::Monday->value)
        ->assertJsonPath('data.dailyHours.0.timeSlots.0.startTime', '09:00 AM');
});

it('OWN-SM-16 returns store operating hours', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
        'is_temporarily_closed' => false,
    ]);

    $response = getJson('/api/v1/store-owner/store/operating-hours');

    $response->assertOk()
        ->assertJsonPath('data.isTemporarilyClosed', false)
        ->assertJsonStructure(['data' => ['dailyHours']]);
});

it('OWN-SM-10 returns low stock products for the store owner', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Low Stock Product',
        'stock_quantity' => 2,
        'low_stock_threshold' => 10,
        'is_available' => true,
    ]);

    $response = getJson('/api/v1/store-owner/products/low-stock');

    $response->assertSuccessful()
        ->assertJsonPath('data.total', 1);
});

it('OWN-SM-11 returns and updates the store profile', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
        'name' => 'Original Store Name',
        'description' => 'Original description',
    ]);

    $show = getJson('/api/v1/store-owner/store');

    $show->assertOk()
        ->assertJsonPath('data.id', $store->id)
        ->assertJsonPath('data.name', 'Original Store Name');

    $update = putJson('/api/v1/store-owner/store', [
        'name' => 'Updated Store Name',
        'description' => 'Updated description',
        'city' => 'Amman',
    ]);

    $update->assertOk()
        ->assertJsonPath('data.name', 'Updated Store Name')
        ->assertJsonPath('data.description', 'Updated description')
        ->assertJsonPath('data.city', 'Amman');
});

it('OWN-SM-15 returns weekly offers summary', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
    ]);

    $offer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $product->id,
    ]);

    $response = getJson('/api/v1/store-owner/offers/weekly-summary');

    $response->assertOk()
        ->assertJsonPath('message', 'Weekly offers analytics retrieved successfully.');
});

it('OWN-SM-17 searches master products and imports them into store catalog', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $masterProduct = MasterProductFactory::new()->create([
        'name' => 'Sparkling Water',
        'barcode' => '111100000001',
        'is_active' => true,
    ]);

    $search = getJson('/api/v1/store-owner/master-products/search?index=Spark');

    $search->assertOk();
    expect(collect($search->json('data'))->pluck('masterProductId')->all())->toContain($masterProduct->id);

    $import = postJson('/api/v1/store-owner/products/from-master', [
        'masterProductIds' => [$masterProduct->id],
    ]);

    $import->assertCreated()
        ->assertJsonPath('data.0.masterProductId', $masterProduct->id)
        ->assertJsonPath('data.0.storeId', $store->id);
});

it('OWN-SM-18 returns filtered activity logs by log name', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    SmStoreFactory::new()->create([
        'owner_user_id' => $seller->id,
    ]);

    $response = getJson('/api/v1/store-owner/activity-logs?logName=products');

    $response->assertOk()->assertJsonStructure(['data']);
});

it('OWN-SM-20 returns shop owner product show and delete protections', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
    $product = SmProductFactory::new()->create(['store_id' => $store->id, 'name' => 'Owner Product']);

    $show = getJson("/api/v1/store-owner/products/{$product->id}");
    $show->assertSuccessful()->assertJsonPath('data.id', $product->id);

    $delete = $this->deleteJson("/api/v1/store-owner/products/{$product->id}");
    $delete->assertNoContent();
});

it('OWN-SM-21 rejects courier handover for non-ready orders', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
    $order = SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Accepted,
    ]);

    postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover")
        ->assertStatus(400);
});

it('OWN-SM-22 returns order return processing validation failure', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($seller);

    $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
    $order = SmOrderFactory::new()->create(['store_id' => $store->id]);

    $response = postJson("/api/v1/store-owner/orders/{$order->id}/return", []);

    $response->assertStatus(422);
});

it('OWN-SM-23 rejects invalid operating hours payload', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($seller);

    SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);

    $response = $this->putJson('/api/v1/store-owner/store/operating-hours', [
        'dailyHours' => [
            [
                'dayOfWeek' => 'not-a-day',
                'isEnabled' => true,
                'timeSlots' => [],
            ],
        ],
    ]);

    $response->assertStatus(422);
});

it('OWN-SM-19 returns supermarket owner permissions catalog', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($seller);

    $response = getJson('/api/v1/store-owner/permissions');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'permissions' => [
                    ['id', 'name', 'slug', 'group'],
                ],
            ],
        ]);
});
