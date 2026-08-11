<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;

beforeEach(function () {
    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('defaults worker extension warning list to current worker pending requests', function () {
    $workerUser = User::factory()->create(['email' => 'worker-scope-current@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create(['email' => 'worker-scope-other@example.com'])->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
    ]);

    $pendingWarning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now()->addMinute(),
    ]);

    $respondedWarning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'worker_response' => 'commit_current_time',
        'worker_responded_at' => now(),
        'sent_at' => now(),
    ]);

    $otherBooking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
    ]);

    $otherWorkerWarning = CleaningTimeWarning::query()->create([
        'booking_id' => $otherBooking->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now()->addMinutes(2),
    ]);

    $response = $this->getJson('/api/v1/cleaning-time-warnings');

    $response->assertOk();
    $ids = array_column($response->json('data'), 'id');

    expect($ids)->toContain($pendingWarning->id);
    expect($ids)->not->toContain($respondedWarning->id);
    expect($ids)->not->toContain($otherWorkerWarning->id);
    expect($response->json('data.0.responseStatus'))->toBe('awaiting_worker_response');
});

it('resolves an extension warning and removes it from the default worker pending list after reject', function () {
    $workerUser = User::factory()->create(['email' => 'worker-scope-reject@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
    ]);

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => 'لا يمكنني التمديد الآن',
    ]);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('commit_current_time');
    expect($response->json('data.responseStatus'))->toBe('resolved');
    expect($response->json('data.status'))->toBe('resolved');
    expect($response->json('data.bookingStatus'))->toBe('completed');
    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Completed);

    $secondResponse = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", []);
    $secondResponse->assertUnprocessable();

    $listResponse = $this->getJson('/api/v1/cleaning-time-warnings');
    $ids = array_column($listResponse->json('data'), 'id');
    expect($ids)->not->toContain($warning->id);
});

it('allows accepted team workers without legacy worker id to reject extension warnings', function () {
    $workerUser = User::factory()->create(['email' => 'worker-scope-assignment@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now(),
    ]);

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", []);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('commit_current_time');
    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Completed);
});
