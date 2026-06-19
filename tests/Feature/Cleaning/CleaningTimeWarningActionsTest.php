<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Jobs\NotifyWorkerExtensionRequestJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
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
    Event::fake([CleaningBookingTrackingUpdated::class, CompletionDecisionMade::class]);

    $workerUser = User::factory()->create(['email' => 'worker-ext-accept@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'total_price' => 100.00,
        'extension_fee_total' => 0,
        'work_finished_at' => now()->subMinutes(5),
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'additional_minutes' => 30,
        'quoted_amount' => 4500,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept", [
        'additionalMinutes' => 45,
    ]);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('extend_time');
    expect($response->json('data.workerRespondedAt'))->not->toBeNull();
    expect($response->json('data.requestedMinutes'))->toBe(30);
    expect((float) $response->json('data.additionalAmount'))->toBe(4500.0);
    expect($response->json('data.currency'))->toBe('SYP');
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'id' => $warning->id,
        'worker_response' => 'extend_time',
    ]);

    $booking->refresh();
    expect($booking->status)->toBe(CleaningBookingStatus::InProgress);
    expect($booking->work_finished_at)->toBeNull();
    expect((float) $booking->extension_fee_total)->toBe(4500.0);
    expect((float) $booking->total_price)->toBe(4600.0);
    expect($warning->fresh()->price_applied_at)->not->toBeNull();

    Event::assertDispatched(CompletionDecisionMade::class, function (CompletionDecisionMade $event) use ($booking, $warning, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->decision === 'extension_accepted'
            && $event->status === CleaningBookingStatus::InProgress->value
            && $event->warningId === $warning->id;
    });

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::InProgress->value;
    });
});

it('rejects an extension request with message', function () {
    Event::fake([CleaningBookingTrackingUpdated::class, CompletionDecisionMade::class]);

    $workerUser = User::factory()->create(['email' => 'worker-ext-reject@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'total_price' => 250.00,
        'extension_fee_total' => 0,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'additional_minutes' => 20,
        'quoted_amount' => 1000,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => 'Sorry, I cannot extend.',
    ]);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('commit_current_time');
    expect($response->json('data.requestedMinutes'))->toBe(20);
    expect((float) $response->json('data.additionalAmount'))->toBe(1000.0);
    expect($response->json('data.currency'))->toBe('SYP');
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'id' => $warning->id,
        'worker_response' => 'commit_current_time',
        'worker_reject_message' => 'Sorry, I cannot extend.',
    ]);

    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(0.0);
    expect((float) $booking->total_price)->toBe(250.0);
    expect($booking->status)->toBe(CleaningBookingStatus::Completed);
    expect($booking->work_finished_at)->not->toBeNull();
    expect($warning->fresh()->price_applied_at)->toBeNull();

    Event::assertDispatched(CompletionDecisionMade::class, function (CompletionDecisionMade $event) use ($booking, $warning, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->decision === 'extension_rejected'
            && $event->status === CleaningBookingStatus::Completed->value
            && $event->warningId === $warning->id
            && $event->message === 'Sorry, I cannot extend.';
    });

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::Completed->value;
    });
});

it('returns 403 when extension request is not for worker booking', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-other@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
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
        'status' => CleaningBookingStatus::TimeExtensionRequested,
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
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'total_price' => 500.00,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'additional_minutes' => 25,
        'quoted_amount' => 1250,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
    ]);

    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept", []);

    $response->assertOk();
    expect($response->json('data.workerResponse'))->toBe('extend_time');
    expect($response->json('data.requestedMinutes'))->toBe(25);
    expect((float) $response->json('data.additionalAmount'))->toBe(1250.0);
    expect($response->json('data.currency'))->toBe('SYP');
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'id' => $warning->id,
        'worker_response' => 'extend_time',
    ]);

    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(1250.0);
    expect((float) $booking->total_price)->toBe(1750.0);
});

it('does not double-apply extension quote when accept is retried', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-accept-retry@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'total_price' => 200.00,
        'extension_fee_total' => 0,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'additional_minutes' => 15,
        'quoted_amount' => 1200,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
    ]);

    $firstResponse = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept", []);
    $firstResponse->assertOk();

    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(1200.0);
    expect((float) $booking->total_price)->toBe(1400.0);

    $secondResponse = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept", []);
    $secondResponse->assertUnprocessable();

    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(1200.0);
    expect((float) $booking->total_price)->toBe(1400.0);
});

it('rejects an extension request without message', function () {
    $workerUser = User::factory()->create(['email' => 'worker-ext-reject-minimal@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
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

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Completed);
});

it('returns 403 when user has no worker on extension accept', function () {
    $regularUser = User::factory()->create(['email' => 'no-worker-ext@example.com']);
    Sanctum::actingAs($regularUser);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
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

it('dispatches worker extension notification when a time warning is created', function () {
    Queue::fake();

    $workerUser = User::factory()->create(['email' => 'worker-ext-realtime@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

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
        'additional_minutes' => 40,
        'quoted_amount' => 3600,
        'quoted_currency' => 'SYP',
    ]);

    Queue::assertPushed(NotifyWorkerExtensionRequestJob::class);
});
