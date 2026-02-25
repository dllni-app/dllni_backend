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
