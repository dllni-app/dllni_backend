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

it('accepts an order successfully', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");

    $response->assertOk();
    expect($response->json('message'))->toBe('Order accepted successfully.');
    expect($response->json('data.status'))->toBe('accepted');
    $this->assertDatabaseHas('sm_orders', [
        'id' => $order->id,
        'status' => 'accepted',
    ]);
});

it('rejects an order with cancellation reason', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'cancellationReason' => 'Out of stock for requested items',
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertOk();
    expect($response->json('message'))->toBe('Order rejected successfully.');
    expect($response->json('data.status'))->toBe('cancelled');
    expect($response->json('data.cancellationReason'))->toBe('Out of stock for requested items');

    $this->assertDatabaseHas('sm_orders', [
        'id' => $order->id,
        'status' => 'cancelled',
        'cancellation_reason' => 'Out of stock for requested items',
    ]);
});

it('requires cancellation reason when rejecting order', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", []);

    $response->assertStatus(422);
    expect($response->json('errors.cancellationReason'))->not->toBeNull();
});

it('validates cancellation reason minimum length', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'cancellationReason' => 'Too short', // Less than 10 characters
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertStatus(422);
    expect($response->json('errors.cancellationReason'))->not->toBeNull();
});

it('validates cancellation reason maximum length', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'cancellationReason' => str_repeat('a', 501), // More than 500 characters
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertStatus(422);
    expect($response->json('errors.cancellationReason'))->not->toBeNull();
});

it('sets cancelled_at timestamp when rejecting order', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
        'cancelled_at' => null,
    ]);

    $payload = [
        'cancellationReason' => 'Store is closing early today',
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertOk();
    expect($response->json('data.cancelledAt'))->not->toBeNull();

    $order->refresh();
    expect($order->cancelled_at)->not->toBeNull();
});

it('returns order with loaded relationships when accepting', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");

    $response->assertOk();
    expect($response->json('data'))->toHaveKeys([
        'id',
        'status',
        'customer',
        'store',
        'items',
    ]);
});

it('returns order with loaded relationships when rejecting', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'cancellationReason' => 'Unable to fulfill order at this time',
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertOk();
    expect($response->json('data'))->toHaveKeys([
        'id',
        'status',
        'customer',
        'store',
        'items',
        'cancellationReason',
    ]);
});
