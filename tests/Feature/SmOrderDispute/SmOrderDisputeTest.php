<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderDisputeFactory;
use Database\Factories\SmOrderFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists order disputes', function (): void {
    SmOrderDisputeFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-order-disputes?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates an order dispute', function (): void {
    $order = SmOrderFactory::new()->create();
    $openedBy = User::factory()->create();

    $payload = [
        'orderId' => $order->id,
        'openedByUserId' => $openedBy->id,
        'ticketNumber' => 'DSP-1001',
        'status' => 'open',
        'reason' => 'Missing items',
    ];

    $response = $this->postJson('/api/v1/sm-order-disputes', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_order_disputes', ['ticket_number' => 'DSP-1001']);
});

it('updates an order dispute', function (): void {
    $dispute = SmOrderDisputeFactory::new()->create(['status' => 'open']);

    $payload = [
        'status' => 'under_review',
    ];

    $response = $this->putJson("/api/v1/sm-order-disputes/{$dispute->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_order_disputes', ['id' => $dispute->id, 'status' => 'under_review']);
});

it('deletes an order dispute', function (): void {
    $dispute = SmOrderDisputeFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-order-disputes/{$dispute->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_order_disputes', ['id' => $dispute->id]);
});
