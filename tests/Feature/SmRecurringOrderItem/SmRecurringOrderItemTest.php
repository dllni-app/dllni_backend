<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmRecurringOrderItemFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists recurring order items', function (): void {
    SmRecurringOrderItemFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-recurring-order-items?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a recurring order item', function (): void {
    $item = SmRecurringOrderItemFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-recurring-order-items/{$item->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($item->id);
});
