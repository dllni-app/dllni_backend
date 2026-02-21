<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmSmartListFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists smart lists', function (): void {
    SmSmartListFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-smart-lists?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a smart list', function (): void {
    $user = User::factory()->create();

    $payload = [
        'userId' => $user->id,
        'name' => 'Weekly items',
        'isActive' => true,
    ];

    $response = $this->postJson('/api/v1/sm-smart-lists', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_smart_lists', ['name' => 'Weekly items']);
});

it('updates a smart list', function (): void {
    $list = SmSmartListFactory::new()->create(['name' => 'Old name']);

    $payload = [
        'name' => 'New name',
    ];

    $response = $this->putJson("/api/v1/sm-smart-lists/{$list->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_smart_lists', ['id' => $list->id, 'name' => 'New name']);
});

it('deletes a smart list', function (): void {
    $list = SmSmartListFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-smart-lists/{$list->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_smart_lists', ['id' => $list->id]);
});
