<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmAssistantQueryFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists assistant queries', function (): void {
    SmAssistantQueryFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-assistant-queries?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows an assistant query', function (): void {
    $query = SmAssistantQueryFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-assistant-queries/{$query->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($query->id);
});
