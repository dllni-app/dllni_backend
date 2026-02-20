<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DashboardPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
    $this->seed(AdminUserSeeder::class);
});

it('logs in with email and password and returns user, permissions and token', function (): void {
    $response = $this->postJson('/api/login', [
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

it('returns validation error when login credentials are invalid', function (): void {
    $response = $this->postJson('/api/login', [
        'email' => 'admin@admin.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('sends reset link for forgot password', function (): void {
    $response = $this->postJson('/api/forgot-password', [
        'email' => 'admin@admin.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', __('passwords.sent'));
});

it('returns validation error when forgot password email does not exist', function (): void {
    $response = $this->postJson('/api/forgot-password', [
        'email' => 'missing@example.com',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('logs out and revokes token', function (): void {
    $admin = User::where('email', 'admin@admin.com')->first();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/logout');

    $response->assertOk();
});

it('returns current user and permissions for me endpoint', function (): void {
    $admin = User::where('email', 'admin@admin.com')->first();
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/me');

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email'],
            'permissions' => [],
        ]);
    expect($response->json('permissions'))->toBeArray();
});

it('rejects me when unauthenticated', function (): void {
    $response = $this->getJson('/api/me');

    $response->assertUnauthorized();
});

it('rejects logout when unauthenticated', function (): void {
    $response = $this->postJson('/api/logout');

    $response->assertUnauthorized();
});
