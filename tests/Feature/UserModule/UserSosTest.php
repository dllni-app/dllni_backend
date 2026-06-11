<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\SOSStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Order;

it('allows a user to create SOS with a valid order and message', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson('/api/user/sos', [
        'order_id' => $order->id,
        'message' => 'The worker did not arrive and I need urgent help.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'SOS request sent successfully.')
        ->assertJsonPath('data.order_id', $order->id)
        ->assertJsonPath('data.message', 'The worker did not arrive and I need urgent help.')
        ->assertJsonPath('data.status', SOSStatus::Pending->value);

    $this->assertDatabaseHas('sos_alerts', [
        'user_id' => $user->id,
        'order_id' => $order->id,
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'message' => 'The worker did not arrive and I need urgent help.',
        'source' => 'user',
        'status' => SOSStatus::Pending->value,
    ]);

    $this->assertDatabaseHas('system_alerts', [
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'alert_type' => AlertType::SOSTriggered->value,
    ]);
});

it('does not allow creating SOS without order_id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/user/sos', [
        'message' => 'I need urgent help.',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['order_id']);
});

it('does not allow creating SOS without message', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson('/api/user/sos', [
        'order_id' => $order->id,
        'message' => '   ',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

it('does not allow creating SOS for another user order', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);
    $order = Order::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->postJson('/api/user/sos', [
        'order_id' => $order->id,
        'message' => 'I need urgent help.',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('sos_alerts', [
        'user_id' => $user->id,
        'order_id' => $order->id,
    ]);
});

it('does not allow unauthenticated users to create SOS', function (): void {
    $response = $this->postJson('/api/user/sos', [
        'order_id' => 1,
        'message' => 'I need urgent help.',
    ]);

    $response->assertUnauthorized();
});
