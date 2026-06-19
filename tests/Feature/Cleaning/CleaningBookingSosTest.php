<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('creates a cleaning booking sos without a permissions gate', function (): void {
    $user = User::factory()->create(['email' => 'cleaning-sos@example.com']);
    Sanctum::actingAs($user);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sos", [
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'I need immediate help at the booking location.',
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'client_request_id' => 'worker-sos-001',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Cleaning booking SOS request sent successfully.')
        ->assertJsonPath('data.booking_id', $booking->id)
        ->assertJsonPath('data.emergency_type', EmergencyType::MedicalEmergency->value)
        ->assertJsonPath('data.message', 'I need immediate help at the booking location.')
        ->assertJsonPath('data.status', SOSStatus::Triggered->value);

    $this->assertDatabaseHas('sos_alerts', [
        'user_id' => $user->id,
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'order_id' => null,
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'I need immediate help at the booking location.',
        'source' => 'booking',
        'status' => SOSStatus::Triggered->value,
    ]);

    $this->assertDatabaseHas('system_alerts', [
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'alert_type' => AlertType::SOSTriggered->value,
    ]);
});
