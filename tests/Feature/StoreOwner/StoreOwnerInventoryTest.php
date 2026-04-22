<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Enums\SmInventoryLogType;
use Modules\Supermarket\Events\ReturnProcessed;
use Modules\Supermarket\Events\StockUpdated;
use Modules\Supermarket\Models\SmLostOpportunity;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($this->seller);
});

describe('Low Stock Alerts', function (): void {
    it('returns products with low stock', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);

        // Products with low stock
        $lowStockProduct1 = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'name' => 'Low Stock Product 1',
            'stock_quantity' => 5,
            'low_stock_threshold' => 10,
            'is_available' => true,
        ]);

        $lowStockProduct2 = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'name' => 'Low Stock Product 2',
            'stock_quantity' => 3,
            'low_stock_threshold' => 5,
            'is_available' => true,
        ]);

        // Product with sufficient stock
        SmProductFactory::new()->create([
            'store_id' => $store->id,
            'stock_quantity' => 50,
            'low_stock_threshold' => 10,
        ]);

        $response = $this->getJson('/api/v1/store-owner/products/low-stock');

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total' => 2,
                ],
            ])
            ->assertJsonPath('data.products.0.product_name', 'Low Stock Product 2') // Ordered by stock asc
            ->assertJsonPath('data.products.1.product_name', 'Low Stock Product 1');
    });

    it('returns forbidden when seller has no store', function (): void {
        $otherSeller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($otherSeller);

        $response = $this->getJson('/api/v1/store-owner/products/low-stock');

        $response->assertForbidden();
    });
});

describe('Inventory summary', function (): void {
    it('returns inventory value and counts for a store', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);

        SmProductFactory::new()->create([
            'store_id' => $store->id,
            'price' => 10,
            'discounted_price' => null,
            'stock_quantity' => 5,
            'low_stock_threshold' => 20,
            'is_available' => true,
        ]);

        SmProductFactory::new()->create([
            'store_id' => $store->id,
            'price' => 100,
            'discounted_price' => 80,
            'stock_quantity' => 2,
            'low_stock_threshold' => 1,
            'is_available' => true,
        ]);

        $response = $this->getJson('/api/v1/store-owner/inventory/summary');

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'inventoryValue' => 210.0,
                    'productSkus' => 2,
                    'lowStockCount' => 1,
                ],
            ]);
    });

    it('returns forbidden for inventory summary when seller has no store', function (): void {
        $otherSeller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($otherSeller);

        $response = $this->getJson('/api/v1/store-owner/inventory/summary');

        $response->assertForbidden();
    });
});

describe('Manual Stock Update', function (): void {
    it('allows setting stock to specific value', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 50,
        ]);

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/stock", [
            'quantity' => 100,
            'operation' => 'SET',
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'product_id' => $product->id,
                    'stock_quantity' => 100,
                ],
            ]);

        expect($product->refresh()->stock_quantity)->toBe(100);

        assertDatabaseHas('sm_inventory_logs', [
            'product_id' => $product->id,
            'type' => SmInventoryLogType::ManualAdjustment->value,
            'quantity_change' => 50,
            'quantity_after' => 100,
        ]);
    });

    it('allows incrementing stock', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 50,
        ]);

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/stock", [
            'quantity' => 25,
            'operation' => 'INCREMENT',
        ]);

        $response->assertSuccessful();

        expect($product->refresh()->stock_quantity)->toBe(75);
    });

    it('allows decrementing stock', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 50,
        ]);

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/stock", [
            'quantity' => 10,
            'operation' => 'DECREMENT',
        ]);

        $response->assertSuccessful();

        expect($product->refresh()->stock_quantity)->toBe(40);
    });

    it('prevents negative stock', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 10,
        ]);

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/stock", [
            'quantity' => 20,
            'operation' => 'DECREMENT',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error' => 'Stock quantity cannot be negative.',
            ]);

        expect($product->refresh()->stock_quantity)->toBe(10);
    });

    it('dispatches StockUpdated event', function (): void {
        Event::fake([StockUpdated::class]);

        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 50,
        ]);

        $this->putJson("/api/v1/store-owner/products/{$product->id}/stock", [
            'quantity' => 100,
            'operation' => 'SET',
        ]);

        Event::assertDispatched(StockUpdated::class, function ($event) use ($product): bool {
            return $event->product->id === $product->id
                && $event->previousStock === 50
                && $event->newStock === 100;
        });
    });
});

describe('Inventory Audit', function (): void {
    it('performs audit with matching stock', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'stock_quantity' => 50,
        ]);

        $response = $this->postJson('/api/v1/store-owner/inventory/audit', [
            'products' => [
                [
                    'product_id' => $product->id,
                    'actual_stock' => 50,
                ],
            ],
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_audited' => 1,
                    'discrepancies_found' => 0,
                    'total_corrected' => 0,
                ],
            ]);
    });

    it('corrects discrepancies found during audit', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $product1 = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'name' => 'Product 1',
            'stock_quantity' => 50,
        ]);
        $product2 = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'name' => 'Product 2',
            'stock_quantity' => 30,
        ]);

        $response = $this->postJson('/api/v1/store-owner/inventory/audit', [
            'products' => [
                [
                    'product_id' => $product1->id,
                    'actual_stock' => 45, // 5 less
                ],
                [
                    'product_id' => $product2->id,
                    'actual_stock' => 35, // 5 more
                ],
            ],
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_audited' => 2,
                    'discrepancies_found' => 2,
                    'total_corrected' => 2,
                ],
            ]);

        expect($product1->refresh()->stock_quantity)->toBe(45);
        expect($product2->refresh()->stock_quantity)->toBe(35);

        assertDatabaseHas('sm_inventory_logs', [
            'product_id' => $product1->id,
            'type' => SmInventoryLogType::AuditCorrection->value,
            'quantity_change' => -5,
            'quantity_after' => 45,
        ]);
    });
});

describe('Product Expiration Management', function (): void {
    it('updates expiration date', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'price' => 100,
        ]);
        $futureDate = now()->addDays(30);

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/expiration", [
            'expires_at' => $futureDate->toIso8601String(),
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'product_id' => $product->id,
                    'is_expiring_soon' => false,
                    'suggested_discount' => null,
                ],
            ]);

        expect($product->refresh()->expires_at->isSameDay($futureDate))->toBeTrue();
    });

    it('suggests discount for expiring soon products', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'price' => 100,
        ]);
        $expiringDate = now()->addDays(5); // Within 7 days

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/expiration", [
            'expires_at' => $expiringDate->toIso8601String(),
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_expiring_soon' => true,
                    'suggested_discount' => [
                        'discount_percentage' => 20,
                        'suggested_price' => 80.0,
                    ],
                ],
            ]);

        expect($response->json('data.suggested_discount.days_until_expiration'))
            ->toBeGreaterThanOrEqual(4)
            ->toBeLessThanOrEqual(5);
    });

    it('rejects past expiration dates', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
        ]);
        $pastDate = now()->subDays(1);

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/expiration", [
            'expires_at' => $pastDate->toIso8601String(),
        ]);

        $response->assertStatus(422);
    });
});

describe('Order Returns', function (): void {
    it('processes return and increases stock', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 20,
        ]);
        $order = SmOrderFactory::new()->create(['store_id' => $store->id]);
        $orderItem = SmOrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);
        $initialStock = $product->stock_quantity;

        $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/return", [
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 3,
                ],
            ],
            'reason' => 'Customer changed mind',
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'order_id' => $order->id,
                    'returned_items' => [
                        [
                            'product_id' => $product->id,
                            'returned_quantity' => 3,
                            'new_stock' => $initialStock + 3,
                        ],
                    ],
                ],
            ]);

        expect($product->refresh()->stock_quantity)->toBe($initialStock + 3);

        assertDatabaseHas('sm_inventory_logs', [
            'product_id' => $product->id,
            'type' => SmInventoryLogType::Return->value,
            'quantity_change' => 3,
        ]);
    });

    it('dispatches ReturnProcessed event', function (): void {
        Event::fake([ReturnProcessed::class]);

        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 20,
        ]);
        $order = SmOrderFactory::new()->create(['store_id' => $store->id]);
        $orderItem = SmOrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $this->postJson("/api/v1/store-owner/orders/{$order->id}/return", [
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 2,
                ],
            ],
            'reason' => 'Defective product',
        ]);

        Event::assertDispatched(ReturnProcessed::class);
    });

    it('prevents returning more than ordered', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
        ]);
        $order = SmOrderFactory::new()->create(['store_id' => $store->id]);
        $orderItem = SmOrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/return", [
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 5,
                ],
            ],
            'reason' => 'Test return',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    });
});

describe('Lost Opportunities Tracking', function (): void {
    it('retrieves lost opportunities report', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $product = SmProductFactory::new()->create(['store_id' => $store->id]);
        $customer = UserFactory::new()->create();

        SmLostOpportunity::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'attempted_quantity' => 10,
            'available_stock' => 5,
        ]);

        SmLostOpportunity::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'customer_id' => null,
            'attempted_quantity' => 15,
            'available_stock' => 5,
        ]);

        $response = $this->getJson('/api/v1/store-owner/reports/lost-opportunities');

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_lost_opportunities' => 2,
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'by_product',
                    'recent_opportunities',
                ],
            ]);
    });

    it('filters by date range', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $product = SmProductFactory::new()->create(['store_id' => $store->id]);

        $oldOpportunity = SmLostOpportunity::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'attempted_quantity' => 10,
            'available_stock' => 5,
            'created_at' => now()->subDays(10),
        ]);

        $recentOpportunity = SmLostOpportunity::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'attempted_quantity' => 10,
            'available_stock' => 5,
            'created_at' => now()->subDays(2),
        ]);

        $startDate = now()->subDays(5)->toDateString();
        $endDate = now()->toDateString();

        $response = $this->getJson(
            "/api/v1/store-owner/reports/lost-opportunities?start_date={$startDate}&end_date={$endDate}"
        );

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_lost_opportunities' => 1,
                ],
            ]);
    });
});

describe('Automatic Stock Deduction on Order Accept', function (): void {
    it('deducts stock when order is accepted', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $order = SmOrderFactory::new()->create(['store_id' => $store->id, 'status' => 'pending']);
        $product1 = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 100,
        ]);
        $product2 = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 50,
        ]);

        SmOrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product1->id,
            'quantity' => 10,
        ]);

        SmOrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'quantity' => 5,
        ]);

        $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");

        $response->assertSuccessful();

        expect($product1->refresh()->stock_quantity)->toBe(90);
        expect($product2->refresh()->stock_quantity)->toBe(45);

        assertDatabaseHas('sm_inventory_logs', [
            'product_id' => $product1->id,
            'type' => SmInventoryLogType::OrderDeduction->value,
            'quantity_change' => -10,
        ]);
    });

    it('prevents accepting order with insufficient stock', function (): void {
        $store = SmStoreFactory::new()->create(['owner_user_id' => $this->seller->id]);
        $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
        $order = SmOrderFactory::new()->create(['store_id' => $store->id, 'status' => 'pending']);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock_quantity' => 5,
        ]);

        SmOrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");

        $response->assertStatus(400);

        // Stock should not be changed
        expect($product->refresh()->stock_quantity)->toBe(5);
    });
});
