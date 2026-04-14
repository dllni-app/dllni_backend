<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCartFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists carts', function (): void {
    SmCartFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-carts?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a cart', function (): void {
    $cartUser = User::factory()->create();

    $payload = [
        'userId' => $cartUser->id,
    ];

    $response = $this->postJson('/api/v1/sm-carts', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_carts', ['user_id' => $cartUser->id]);
});

it('deletes a cart', function (): void {
    $cart = SmCartFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-carts/{$cart->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_carts', ['id' => $cart->id]);
});
