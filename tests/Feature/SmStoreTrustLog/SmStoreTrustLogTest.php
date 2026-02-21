<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmStoreTrustLogFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists store trust logs', function (): void {
    SmStoreTrustLogFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-store-trust-logs?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a store trust log', function (): void {
    $log = SmStoreTrustLogFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-store-trust-logs/{$log->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($log->id);
});
