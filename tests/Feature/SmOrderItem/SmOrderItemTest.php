<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderItemFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists order items', function (): void {
    SmOrderItemFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-order-items?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows an order item', function (): void {
    $item = SmOrderItemFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-order-items/{$item->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($item->id);
});
