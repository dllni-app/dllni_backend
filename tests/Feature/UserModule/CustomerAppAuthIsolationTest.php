<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('rejects cleaning worker credentials on both customer login routes', function (): void {
    $worker = User::factory()->phoneVerified()->create([
        'phone' => '+963944001982',
        'password' => 'worker-password123',
        'module_type' => UserModuleType::CleaningWorker,
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/user/login', [
        'phone' => $worker->phone,
        'password' => 'worker-password123',
    ])->assertUnprocessable()->assertJsonMissingPath('token');

    $this->postJson('/api/login', [
        'phone' => $worker->phone,
        'password' => 'worker-password123',
        'module' => 'user',
    ])->assertUnprocessable()->assertJsonMissingPath('token');

    // Even supplying an OTP must not allow token issuance for the customer app.
    $this->postJson('/api/v1/user/login/verify', [
        'phone' => $worker->phone,
        'password' => 'worker-password123',
        'otp' => '123456',
    ])->assertUnprocessable()->assertJsonMissingPath('token');
});

it('rejects a cleaning worker bearer token on authenticated customer endpoints', function (): void {
    $worker = User::factory()->phoneVerified()->create([
        'module_type' => UserModuleType::CleaningWorker,
    ]);
    Sanctum::actingAs($worker);

    $this->getJson('/api/v1/user/me')
        ->assertForbidden()
        ->assertJsonPath('message', 'هذا الحساب غير مصرح له باستخدام تطبيق العميل.');

    $this->getJson('/api/v1/user/addresses')->assertForbidden();
});

it('allows a customer bearer token on authenticated customer endpoints', function (): void {
    $customer = User::factory()->phoneVerified()->create();
    expect($customer->getAttributes())->toHaveKey('module_type')
        ->and($customer->module_type)->toBeNull();
    Sanctum::actingAs($customer);

    $this->getJson('/api/v1/user/me')
        ->assertOk()
        ->assertJsonPath('user.id', $customer->id);
});

it('rejects a seller bearer token on authenticated customer endpoints', function (): void {
    $seller = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller,
    ]);
    Sanctum::actingAs($seller);

    $this->getJson('/api/v1/user/me')->assertForbidden();
});

it('requires authentication for protected customer endpoints', function (): void {
    $this->getJson('/api/v1/user/me')->assertUnauthorized();
});
