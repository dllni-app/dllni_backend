<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmSmartListItemFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists smart list items', function (): void {
    SmSmartListItemFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-smart-list-items?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a smart list item', function (): void {
    $item = SmSmartListItemFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-smart-list-items/{$item->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($item->id);
});
