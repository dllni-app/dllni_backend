<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmStoreDailyStatFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists store daily stats', function (): void {
    SmStoreDailyStatFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-store-daily-stats?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a store daily stat', function (): void {
    $stat = SmStoreDailyStatFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-store-daily-stats/{$stat->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($stat->id);
});
