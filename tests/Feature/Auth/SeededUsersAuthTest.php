<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\CleaningWorkerAndSellerSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(CleaningWorkerAndSellerSeeder::class);
});

it('user login with seeded cleaning worker phone returns user and token', function (): void {
    $response = $this->postJson('/api/login', [
        'phone' => '+963944100001',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'cleaning.worker@example.com')
        ->assertJsonPath('user.name', 'Cleaning Worker')
        ->assertJsonPath('user.phone', '+963944100001')
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'phone'], 'token']);
    expect($response->json('token'))->toBeString();
});

it('user login with seeded seller phone returns user and token', function (): void {
    $response = $this->postJson('/api/login', [
        'phone' => '+963944100002',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'seller@example.com')
        ->assertJsonPath('user.name', 'Restaurant Seller')
        ->assertJsonPath('user.phone', '+963944100002')
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'phone'], 'token']);
    expect($response->json('token'))->toBeString();
});

it('user login fails for seeded cleaning worker with wrong password', function (): void {
    $response = $this->postJson('/api/login', [
        'phone' => '+963944100001',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);
});

it('seeded cleaning worker can logout with token', function (): void {
    $user = User::where('email', 'cleaning.worker@example.com')->first();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/logout');

    $response->assertOk();
});

it('seeded seller can logout with token', function (): void {
    $user = User::where('email', 'seller@example.com')->first();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/logout');

    $response->assertOk();
});
