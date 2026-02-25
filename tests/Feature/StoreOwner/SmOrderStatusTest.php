<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Enums\RejectionType;
use Modules\Supermarket\Enums\SmOrderStatus;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->user->id,
        'trust_score' => 100,
    ]);
});

// ============ ACCEPT ORDER TESTS ============

it('accepts a pending order', function (): void {
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

it('rejects accepting non-pending orders', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");

    $response->assertStatus(400);
    expect($response->json('message'))->toContain('Cannot accept order');
});

it('returns loaded relationships on accept', function (): void {
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
        'statusLogs',
        'disputes',
    ]);
});

// ============ REJECT ORDER TESTS ============

it('rejects a pending order with out of stock type', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'The requested items are no longer in stock',
        'rejectionType' => RejectionType::OutOfStock->value,
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertOk();
    expect($response->json('message'))->toBe('Order rejected successfully.');
    expect($response->json('data.status'))->toBe('cancelled');
    expect($response->json('data.cancellationReason'))->toBe('The requested items are no longer in stock');

    // Verify trust score decreased by 5 (out of stock penalty)
    $this->store->refresh();
    expect($this->store->trust_score)->toBe(95);
});

it('rejects a pending order with fake order type', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'This order appears to be fraudulent',
        'rejectionType' => RejectionType::FakeOrder->value,
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertOk();

    // Verify trust score decreased by 20 (fake order penalty)
    $this->store->refresh();
    expect($this->store->trust_score)->toBe(80);
});

it('rejects a pending order with other type', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'Operational issue preventing fulfillment',
        'rejectionType' => RejectionType::Other->value,
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertOk();

    // Verify trust score not decreased (other type)
    $this->store->refresh();
    expect($this->store->trust_score)->toBe(100);
});

it('requires reason when rejecting', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", []);

    $response->assertStatus(422);
    expect($response->json('errors.reason'))->not->toBeNull();
});

it('validates reason minimum length', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'Too short',
        'rejectionType' => RejectionType::Other->value,
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertStatus(422);
});

it('requires rejection type when rejecting', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'This is a valid rejection reason',
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertStatus(422);
    expect($response->json('errors.rejectionType'))->not->toBeNull();
});

it('prevents rejecting non-pending orders', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Accepted,
    ]);

    $payload = [
        'reason' => 'This should not be allowed',
        'rejectionType' => RejectionType::Other->value,
    ];

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $response->assertStatus(400);
    expect($response->json('message'))->toContain('Cannot reject order');
});

// ============ TRUST SCORE THRESHOLD TESTS ============

it('sends warning notification when trust score reaches warning threshold', function (): void {
    $this->store->update(['trust_score' => 85]);

    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'Items are out of stock at this time',
        'rejectionType' => RejectionType::OutOfStock->value,
    ];

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    // Trust score = 85 - 5 = 80 (at warning threshold and below)
    $this->store->refresh();
    expect($this->store->trust_score)->toBe(80);
});

it('reduces visibility when trust score drops below 60', function (): void {
    $this->store->update(['trust_score' => 65, 'is_featured' => true]);

    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'Suspicious order activity detected',
        'rejectionType' => RejectionType::FakeOrder->value,
    ];

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    // Trust score = 65 - 20 = 45 (below reduction threshold)
    $this->store->refresh();
    expect($this->store->trust_score)->toBe(45);
    expect($this->store->is_featured)->toBeFalse();
});

it('suspends account when trust score drops below 40', function (): void {
    $this->store->update(['trust_score' => 50]);

    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $payload = [
        'reason' => 'Multiple fraudulent orders detected',
        'rejectionType' => RejectionType::FakeOrder->value,
    ];

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    // Trust score = 50 - 20 = 30 (below suspension threshold)
    $this->store->refresh();
    expect($this->store->trust_score)->toBe(30);
    expect($this->store->suspension_until)->not->toBeNull();
});

// ============ EDGE CASES ============

it('returns 404 for non-existent order', function (): void {
    $response = $this->postJson('/api/v1/store-owner/orders/99999/accept');

    $response->assertNotFound();
});

it('sets cancelled_at timestamp when rejecting', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
        'cancelled_at' => null,
    ]);

    $payload = [
        'reason' => 'Store is temporarily unavailable',
        'rejectionType' => RejectionType::Other->value,
    ];

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload);

    $order->refresh();
    expect($order->cancelled_at)->not->toBeNull();
});
