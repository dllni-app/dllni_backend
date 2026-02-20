<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists workers', function () {
    Worker::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/workers');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a worker', function () {
    $user = User::factory()->create(['email' => 'worker-user@example.com']);

    $payload = [
        'userId' => $user->id,
        'firstName' => 'Ahmed',
        'trustScore' => 85,
        'isActive' => true,
    ];

    $response = $this->postJson('/api/v1/workers', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('workers', [
        'user_id' => $user->id,
        'first_name' => 'Ahmed',
    ]);
});

it('shows a worker', function () {
    $worker = Worker::factory()->create();

    $response = $this->getJson("/api/v1/workers/{$worker->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($worker->id);
});

it('updates a worker', function () {
    $worker = Worker::factory()->create(['trust_score' => 70]);

    $response = $this->putJson("/api/v1/workers/{$worker->id}", [
        'userId' => $worker->user_id,
        'firstName' => $worker->first_name,
        'trustScore' => 90,
        'isActive' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('workers', [
        'id' => $worker->id,
        'trust_score' => 90,
    ]);
});

it('deletes a worker', function () {
    $worker = Worker::factory()->create();

    $response = $this->deleteJson("/api/v1/workers/{$worker->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('workers', ['id' => $worker->id]);
});
