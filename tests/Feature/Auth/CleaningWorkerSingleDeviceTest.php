<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;

it('invalidates the prior worker API token when a new device signs in', function (): void {
    $phone = '+963944000982';
    $workerAccount = User::factory()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
        'module_type' => UserModuleType::CleaningWorker->value,
        'is_active' => true,
    ]);

    $firstDevice = $workerAccount->createToken('user-api');
    $newDevice = $this->postJson('/api/login', [
        'phone' => $phone,
        'password' => 'secret123',
        'module' => UserModuleType::CleaningWorker->value,
    ]);

    $newDevice->assertOk()->assertJsonStructure(['token']);
    expect($workerAccount->tokens()->whereKey($firstDevice->accessToken->id)->exists())->toBeFalse()
        ->and($workerAccount->tokens()->where('name', 'user-api')->count())->toBe(1);
});

it('retains non-worker sessions for other application types', function (): void {
    $phone = '+963944000983';
    $customer = User::factory()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
        'module_type' => null,
        'is_active' => true,
    ]);
    $firstDevice = $customer->createToken('user-api');

    $this->postJson('/api/login', [
        'phone' => $phone,
        'password' => 'secret123',
        'module' => 'user',
    ])->assertOk();

    expect($customer->tokens()->whereKey($firstDevice->accessToken->id)->exists())->toBeTrue();
});
