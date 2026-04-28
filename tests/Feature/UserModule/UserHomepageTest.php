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

it('returns restaurant homepage featured offers without authentication', function (): void {
    // Act
    $response = $this->getJson('/api/v1/user/restaurants/home/featured-offers');

    // Assert
    $response->assertOk()->assertJsonStructure(['offers']);
});

it('returns supermarket browse stores without authentication', function (): void {
    // Act
    $response = $this->getJson('/api/v1/user/supermarket/stores');

    // Assert
    $response->assertOk()->assertJsonStructure([
        'data',
        'links',
        'meta',
    ]);
});

it('returns restaurant details without authentication', function (): void {
    // Arrange
    $restaurant = Modules\Resturants\Models\Restaurant::factory()->create([
        'name' => 'IndoMart',
        'is_active' => true,
    ]);

    // Act
    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}");

    // Assert
    $response
        ->assertOk()
        ->assertJsonStructure([
            'restaurant' => ['id', 'name', 'isActive'],
            'offers',
            'popularProducts',
            'categories',
            'ratingSummary' => ['average', 'total', 'counts'],
            'reviews',
        ])
        ->assertJsonPath('restaurant.id', $restaurant->id)
        ->assertJsonPath('restaurant.name', 'IndoMart')
        ->assertJsonPath('restaurant.isActive', true);
});

it('returns supermarket order status for authenticated user', function (): void {
    // Arrange
    $user = User::factory()->create(['phone' => '+963944888000']);
    Sanctum::actingAs($user);

    $order = Modules\Supermarket\Models\SmOrder::factory()->create([
        'customer_id' => $user->id,
    ]);

    // Act
    $response = $this->getJson("/api/v1/user/supermarket/orders/{$order->id}/status");

    // Assert
    $response
        ->assertOk()
        ->assertJsonStructure([
            'order' => [
                'id',
                'orderNumber',
                'status',
                'storeId',
                'items',
                'statusLogs',
            ],
        ])
        ->assertJsonPath('order.id', $order->id);
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
