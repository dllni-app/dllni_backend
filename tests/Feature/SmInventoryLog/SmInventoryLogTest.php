<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmInventoryLogFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists inventory logs', function (): void {
    SmInventoryLogFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-inventory-logs?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows an inventory log', function (): void {
    $log = SmInventoryLogFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-inventory-logs/{$log->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($log->id);
});
