<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

it('creates a cleaning booking sos without a permissions gate', function (): void {
    $user = User::factory()->create(['email' => 'cleaning-sos@example.com']);
    $worker = Worker::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'worker_id' => $worker->id,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::StartApproved->value,
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

it('rejects worker cleaning sos when worker is not assigned to the booking', function (): void {
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sos", [
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'Need urgent help',
    ])->assertForbidden();
});

it('rejects worker cleaning sos for completed bookings', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed,
        'worker_id' => $worker->id,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::StartApproved->value,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sos", [
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'Need urgent help',
    ])->assertUnprocessable();
});

it('returns an existing active worker cleaning sos instead of creating duplicates', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'worker_id' => $worker->id,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::StartApproved->value,
    ]);

    $payload = [
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'Need urgent help',
    ];

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sos", $payload)->assertCreated();
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sos", $payload)
        ->assertOk()
        ->assertJsonPath('message', 'Cleaning booking SOS request already exists.');

    expect(\App\Models\SosAlert::query()
        ->where('booking_id', $booking->id)
        ->where('booking_type', CleaningBooking::class)
        ->count())->toBe(1);
});
