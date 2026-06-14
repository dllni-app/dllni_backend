<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Order;

it('allows a user to create a cleaning order SOS with location and emergency type', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$order->id}/sos", [
        'emergency_type' => EmergencyType::SafetyThreat->value,
        'message' => 'I need immediate help at the cleaning location.',
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'client_request_id' => 'abc-123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Cleaning SOS request sent successfully.')
        ->assertJsonPath('data.order_id', null)
        ->assertJsonPath('data.booking_id', $order->id)
        ->assertJsonPath('data.emergency_type', EmergencyType::SafetyThreat->value)
        ->assertJsonPath('data.message', 'I need immediate help at the cleaning location.')
        ->assertJsonPath('data.status', SOSStatus::Pending->value);

    $this->assertDatabaseHas('sos_alerts', [
        'user_id' => $user->id,
        'order_id' => $order->id,
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'emergency_type' => EmergencyType::SafetyThreat->value,
        'message' => 'I need immediate help at the cleaning location.',
        'source' => 'user_cleaning_order',
        'status' => SOSStatus::Pending->value,
    ]);

    $this->assertDatabaseHas('system_alerts', [
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'alert_type' => AlertType::SOSTriggered->value,
    ]);
});

it('does not allow a cleaning SOS for another user order', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);
    $order = Order::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$order->id}/sos", [
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'Help.',
        'latitude' => 33.5,
        'longitude' => 36.2,
    ]);

    $response->assertForbidden();
});
