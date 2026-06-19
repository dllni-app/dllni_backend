<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;

it('allows a user to create a cleaning order SOS with location and emergency type', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $booking = CleaningBooking::factory()->create(['customer_id' => $user->id]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/sos", [
        'emergency_type' => EmergencyType::SafetyThreat->value,
        'message' => 'I need immediate help at the cleaning location.',
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'client_request_id' => 'abc-123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Cleaning SOS request sent successfully.')
        ->assertJsonPath('data.booking_id', $booking->id)
        ->assertJsonPath('data.emergency_type', EmergencyType::SafetyThreat->value)
        ->assertJsonPath('data.message', 'I need immediate help at the cleaning location.')
        ->assertJsonPath('data.source', 'booking')
        ->assertJsonPath('data.status', SOSStatus::Triggered->value);

    $this->assertDatabaseHas('sos_alerts', [
        'user_id' => $user->id,
        'order_id' => null,
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'emergency_type' => EmergencyType::SafetyThreat->value,
        'message' => 'I need immediate help at the cleaning location.',
        'source' => 'booking',
        'status' => SOSStatus::Triggered->value,
    ]);

    $this->assertDatabaseHas('system_alerts', [
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'alert_type' => AlertType::SOSTriggered->value,
    ]);
});

it('normalizes camelCase cleaning SOS payload fields', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $booking = CleaningBooking::factory()->create(['customer_id' => $user->id]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/sos", [
        'emergencyType' => EmergencyType::MedicalEmergency->value,
        'message' => 'Need help with a medical emergency.',
        'lat' => 33.5,
        'lng' => 36.2,
        'clientRequestId' => 'client-123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.booking_id', $booking->id)
        ->assertJsonPath('data.emergency_type', EmergencyType::MedicalEmergency->value)
        ->assertJsonPath('data.latitude', '33.50000000')
        ->assertJsonPath('data.longitude', '36.20000000');
});

it('returns an existing active cleaning SOS instead of creating duplicates', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $booking = CleaningBooking::factory()->create(['customer_id' => $user->id]);

    $payload = [
        'emergency_type' => EmergencyType::SevereConflict->value,
        'message' => 'There is a severe conflict at the location.',
    ];

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/sos", $payload)->assertCreated();
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/sos", $payload)
        ->assertOk()
        ->assertJsonPath('message', 'Cleaning SOS request already exists.');

    expect(\App\Models\SosAlert::query()
        ->where('booking_id', $booking->id)
        ->where('booking_type', CleaningBooking::class)
        ->count())->toBe(1);
});

it('does not allow a cleaning SOS for another user order', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);
    $booking = CleaningBooking::factory()->create(['customer_id' => $otherUser->id]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/sos", [
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'Help.',
        'latitude' => 33.5,
        'longitude' => 36.2,
    ]);

    $response->assertNotFound();
});
