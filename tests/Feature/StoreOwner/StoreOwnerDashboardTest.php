<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Enums\SmOrderStatus;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    // Create a store owned by the authenticated user
    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->user->id,
    ]);
});

it('retrieves dashboard data for a store', function (): void {
    // Create some orders for today
    SmOrderFactory::new()->count(5)->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->count(3)->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    expect($response->json('data.totalOrders'))->toBe(8);
    expect($response->json('data.completedOrders'))->toBe(3);
    expect($response->json('data.newOrders'))->toBe(5);
});

it('requires storeId parameter', function (): void {
    $response = $this->getJson('/api/v1/store-owner/dashboard');

    $response->assertStatus(422);
    expect($response->json('errors.storeId'))->not->toBeNull();
});

it('validates storeId exists', function (): void {
    $response = $this->getJson('/api/v1/store-owner/dashboard?storeId=99999');

    $response->assertStatus(422);
});

it('counts pending orders correctly', function (): void {
    // Create orders with different statuses
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Accepted,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Preparing,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Cancelled,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    // Pending orders = not completed and not cancelled = 3 (Pending, Accepted, Preparing)
    expect($response->json('data.pendingOrders'))->toBe(3);
});

it('calculates total sales from completed orders only', function (): void {
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 100.50,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 50.25,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
        'total_amount' => 200.00,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    expect($response->json('data.totalSales'))->toBe(150.75);
});

it('calculates positive percentage when sales increase from yesterday', function (): void {
    // Yesterday's sales: 100.00
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 100.00,
        'created_at' => now()->subDay(),
    ]);

    // Today's sales: 150.00
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 150.00,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    // (150 - 100) / 100 * 100 = 50%
    expect($response->json('data.salesPercentageChange'))->toEqual(50.0);
});

it('calculates negative percentage when sales decrease from yesterday', function (): void {
    // Yesterday's sales: 200.00
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 200.00,
        'created_at' => now()->subDay(),
    ]);

    // Today's sales: 150.00
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 150.00,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    // (150 - 200) / 200 * 100 = -25%
    expect($response->json('data.salesPercentageChange'))->toEqual(-25.0);
});

it('returns zero percentage when yesterday had no sales', function (): void {
    // No orders yesterday

    // Today's sales: 100.00
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 100.00,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    // When yesterday = 0, should return 0 to avoid division by zero
    expect($response->json('data.salesPercentageChange'))->toBe(0);
});

it('returns zero percentage when sales are equal to yesterday', function (): void {
    // Yesterday's sales: 100.00
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 100.00,
        'created_at' => now()->subDay(),
    ]);

    // Today's sales: 100.00
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 100.00,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    // (100 - 100) / 100 * 100 = 0%
    expect($response->json('data.salesPercentageChange'))->toEqual(0.0);
});

it('only includes completed orders in sales percentage calculation', function (): void {
    // Yesterday: 100.00 completed + 50.00 pending (ignored)
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 100.00,
        'created_at' => now()->subDay(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
        'total_amount' => 50.00,
        'created_at' => now()->subDay(),
    ]);

    // Today: 120.00 completed + 80.00 cancelled (ignored)
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 120.00,
        'created_at' => now(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Cancelled,
        'total_amount' => 80.00,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    // (120 - 100) / 100 * 100 = 20%
    expect($response->json('data.salesPercentageChange'))->toEqual(20.0);
});

it('calculates percentage with decimal precision', function (): void {
    // Yesterday's sales: 123.45
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 123.45,
        'created_at' => now()->subDay(),
    ]);

    // Today's sales: 135.79
    SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
        'total_amount' => 135.79,
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/store-owner/dashboard?storeId={$this->store->id}");

    $response->assertOk();
    // (135.79 - 123.45) / 123.45 * 100 ≈ 10.00%
    expect($response->json('data.salesPercentageChange'))->toEqual(10.0);
});
