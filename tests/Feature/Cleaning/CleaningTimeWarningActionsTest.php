<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
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

it('accepts an extension request', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-accept@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept", [
        'additionalMinutes' => 30,
    ]);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('extend_time');
    expect($response->json('data.workerRespondedAt'))->not->toBeNull();
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'id' => $warning->id,
        'worker_response' => 'extend_time',
    ]);
});

it('rejects an extension request with message', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-reject@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => 'Sorry, I cannot extend.',
    ]);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('commit_current_time');
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'id' => $warning->id,
        'worker_response' => 'commit_current_time',
        'worker_reject_message' => 'Sorry, I cannot extend.',
    ]);
});

it('returns 403 when extension request is not for worker booking', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-other@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept");

    $response->assertForbidden();
});

it('returns 422 when extension request already responded', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-done@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => 'extend_time',
        'worker_responded_at' => now(),
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept");

    $response->assertUnprocessable();
});

it('accepts an extension request without additionalMinutes', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-accept-minimal@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept", []);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('extend_time');
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'id' => $warning->id,
        'worker_response' => 'extend_time',
    ]);
});

it('rejects an extension request without message', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-reject-minimal@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", []);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('commit_current_time');
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'id' => $warning->id,
        'worker_response' => 'commit_current_time',
    ]);
});

it('returns 403 when user has no worker on extension accept', function () {
    $regularUser = User::factory()->create(['email' => 'no-worker-ext@example.com']);
    Sanctum::actingAs($regularUser);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept");

    $response->assertForbidden();
});

it('returns 404 when time warning does not exist', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-404@example.com']);
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $response = $this->postJson('/api/v1/cleaning-time-warnings/99999/accept');

    $response->assertNotFound();
});

it('lists pending extension requests for current worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-list@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $pendingWarning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/cleaning-time-warnings?filter[forCurrentWorker]=1&filter[pending]=1');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
    $ids = array_column($response->json('data'), 'id');
    expect($ids)->toContain($pendingWarning->id);
});
