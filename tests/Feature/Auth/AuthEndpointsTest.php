<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DashboardPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
    $this->seed(AdminUserSeeder::class);
});

it('dashboard: logs in with email and password and returns user, permissions and token', function (): void {
    $response = $this->postJson('/api/dashboard/login', [
        'email' => 'admin@admin.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email'],
            'permissions' => [],
            'token',
        ]);
    expect($response->json('permissions'))->toBeArray();
    expect($response->json('token'))->toBeString();
});

it('dashboard: returns validation error when login credentials are invalid', function (): void {
    $response = $this->postJson('/api/dashboard/login', [
        'email' => 'admin@admin.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('dashboard: sends reset link for forgot password', function (): void {
    $response = $this->postJson('/api/dashboard/forgot-password', [
        'email' => 'admin@admin.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', __('passwords.sent'));
});

it('dashboard: returns validation error when forgot password email does not exist', function (): void {
    $response = $this->postJson('/api/dashboard/forgot-password', [
        'email' => 'missing@example.com',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('dashboard: logs out and revokes token', function (): void {
    $admin = User::where('email', 'admin@admin.com')->first();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/dashboard/logout');

    $response->assertOk();
});

it('dashboard: returns current user and permissions for me endpoint', function (): void {
    $admin = User::where('email', 'admin@admin.com')->first();
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/dashboard/me');

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email'],
            'permissions' => [],
        ]);
    expect($response->json('permissions'))->toBeArray();
});

it('dashboard: rejects me when unauthenticated', function (): void {
    $response = $this->getJson('/api/dashboard/me');

    $response->assertUnauthorized();
});

it('dashboard: rejects logout when unauthenticated', function (): void {
    $response = $this->postJson('/api/dashboard/logout');

    $response->assertUnauthorized();
});

it('user: logs in with phone and password and returns user and token', function (): void {
    $user = User::factory()->create([
        'phone' => '+962791234567',
        'password' => bcrypt('secret'),
    ]);

    $response = $this->postJson('/api/login', [
        'phone' => '+962791234567',
        'password' => 'secret',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'phone'],
            'token',
        ]);
    expect($response->json('user.phone'))->toBe('+962791234567');
    expect($response->json('token'))->toBeString();
});

it('user: returns validation error when login credentials are invalid', function (): void {
    User::factory()->create(['phone' => '+962791234567']);

    $response = $this->postJson('/api/login', [
        'phone' => '+962791234567',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);
});

it('user: logs out and revokes token', function (): void {
    $user = User::factory()->create(['phone' => '+962791234567']);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/logout');

    $response->assertOk();
});

it('user: rejects logout when unauthenticated', function (): void {
    $response = $this->postJson('/api/logout');

    $response->assertUnauthorized();
});
