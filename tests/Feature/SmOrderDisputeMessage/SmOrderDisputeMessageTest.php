<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderDisputeFactory;
use Database\Factories\SmOrderDisputeMessageFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists order dispute messages', function (): void {
    SmOrderDisputeMessageFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-order-dispute-messages?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates an order dispute message', function (): void {
    $dispute = SmOrderDisputeFactory::new()->create();
    $user = User::factory()->create();

    $payload = [
        'disputeId' => $dispute->id,
        'userId' => $user->id,
        'message' => 'We are reviewing your dispute.',
        'isInternal' => false,
    ];

    $response = $this->postJson('/api/v1/sm-order-dispute-messages', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_order_dispute_messages', ['message' => 'We are reviewing your dispute.']);
});

it('updates an order dispute message', function (): void {
    $message = SmOrderDisputeMessageFactory::new()->create(['message' => 'Initial']);

    $payload = [
        'message' => 'Updated message',
    ];

    $response = $this->putJson("/api/v1/sm-order-dispute-messages/{$message->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_order_dispute_messages', ['id' => $message->id, 'message' => 'Updated message']);
});

it('deletes an order dispute message', function (): void {
    $message = SmOrderDisputeMessageFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-order-dispute-messages/{$message->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_order_dispute_messages', ['id' => $message->id]);
});
