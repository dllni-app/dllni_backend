<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderStatusLogFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists order status logs', function (): void {
    SmOrderStatusLogFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-order-status-logs?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows an order status log', function (): void {
    $log = SmOrderStatusLogFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-order-status-logs/{$log->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($log->id);
});
