<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns supermarket homepage featured offers without authentication', function (): void {
    // Act
    $response = $this->getJson('/api/v1/user/supermarket/home/featured-offers');

    // Assert
    $response->assertOk()->assertJsonStructure(['offers']);
});

it('returns supermarket nearby stores without authentication', function (): void {
    // Act
    $response = $this->getJson('/api/v1/user/supermarket/home/nearby-stores');

    // Assert
    $response->assertOk()->assertJsonStructure(['stores']);
});

it('returns current user when authenticated', function (): void {
    // Arrange
    $user = User::factory()->create([
        'name' => 'Ahmed',
        'phone' => '+963944999000',
    ]);

    Sanctum::actingAs($user);

    // Act
    $response = $this->getJson('/api/v1/user/me');

    // Assert
    $response->assertOk()->assertJsonStructure([
        'user' => ['id', 'name', 'email', 'phone'],
    ]);
});
