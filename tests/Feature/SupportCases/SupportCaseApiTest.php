<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\EmergencyType;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCaseStatus;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('creates one active emergency support case per reporter and booking', function (): void {
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    Sanctum::actingAs($customer);

    $payload = [
        'kind' => SupportCaseKind::Emergency->value,
        'bookingId' => $booking->id,
        'emergencyType' => EmergencyType::SafetyThreat->value,
        'description' => 'I need immediate help at the booking location.',
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'clientRequestId' => 'customer-emergency-1',
    ];

    $first = $this->postJson('/api/v1/support-cases', $payload);
    $second = $this->postJson('/api/v1/support-cases', $payload);

    $first->assertCreated()
        ->assertJsonPath('data.kind', 'emergency')
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.bookingId', $booking->id);

    $second->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(SupportCase::query()->count())->toBe(1);
});

it('creates a complaint and places the cleaning booking under dispute', function (): void {
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Completed,
    ]);

    Sanctum::actingAs($customer);

    $this->postJson('/api/v1/support-cases', [
        'kind' => SupportCaseKind::Complaint->value,
        'bookingId' => $booking->id,
        'category' => DisputeCategory::PoorQuality->value,
        'description' => 'The completed service did not match the agreed quality.',
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'complaint')
        ->assertJsonPath('data.workerEarningsFrozen', true);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::UnderDispute);
});

it('allows the assigned worker to send emergency cases but not complaints', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    Sanctum::actingAs($workerUser);

    $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => $booking->id,
        'emergencyType' => EmergencyType::MedicalEmergency->value,
        'description' => 'Medical emergency during the cleaning mission.',
    ])->assertCreated()
        ->assertJsonPath('data.reporterRole', 'worker');

    $this->postJson('/api/v1/support-cases', [
        'kind' => 'complaint',
        'bookingId' => $booking->id,
        'category' => DisputeCategory::Other->value,
        'description' => 'Worker complaint attempt.',
    ])->assertForbidden();
});

it('lets booking participants read and reply to a support case', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);
    $supportCase = SupportCase::query()->create([
        'case_number' => 'CMP-TEST-001',
        'kind' => 'complaint',
        'priority' => 'normal',
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'reporter_id' => $customer->id,
        'reporter_role' => 'customer',
        'category' => DisputeCategory::Other->value,
        'description' => 'Need more information.',
        'status' => SupportCaseStatus::UnderReview,
    ]);

    Sanctum::actingAs($workerUser);

    $this->getJson("/api/v1/support-cases/{$supportCase->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $supportCase->id);

    $this->postJson("/api/v1/support-cases/{$supportCase->id}/messages", [
        'message' => 'Here are the requested details from the worker.',
    ])->assertOk()
        ->assertJsonPath('data.messages.0.senderRole', 'worker');
});
