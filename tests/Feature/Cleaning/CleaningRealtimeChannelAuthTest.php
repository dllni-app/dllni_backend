<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Providers\AppServiceProvider;

beforeEach(function (): void {
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher', [
        'driver' => 'pusher',
        'key' => 'test-pusher-key',
        'secret' => 'test-pusher-secret',
        'app_id' => 'test-pusher-app-id',
        'options' => [
            'cluster' => 'eu',
            'useTLS' => true,
        ],
    ]);

    (new AppServiceProvider(app()))->boot();
});

it('authorizes the assigned worker for private cleaning worker channel', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $token = $workerUser->createToken('user-api')->plainTextToken;

    $response = $this
        ->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->post('/broadcasting/auth', [
            'socket_id' => '267922.520045',
            'channel_name' => "private-cleaning-worker.{$worker->id}",
        ]);

    $response->assertOk()
        ->assertJsonStructure(['auth']);
});

it('forbids subscribing to another worker private channel', function (): void {
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create();
    $token = $workerUser->createToken('user-api')->plainTextToken;

    $response = $this
        ->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->post('/broadcasting/auth', [
            'socket_id' => '267922.520045',
            'channel_name' => "private-cleaning-worker.{$otherWorker->id}",
        ]);

    $response->assertForbidden();
});
