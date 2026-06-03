<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CleaningOrderAwaitingCustomerCompletion;
use Modules\Cleaning\Events\CleaningOrderAwaitingStartVerification;
use Modules\Cleaning\Events\WorkerArrived;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function () {
    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('accepts a cleaning booking when status is pending (worker takes order)', function () {
    Event::fake([CleaningBookingTrackingUpdated::class]);

    $workerUser = User::factory()->create(['email' => 'worker-accept@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.3,
    ]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'address_latitude' => 33.5,
        'address_longitude' => 36.3,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('worker_assigned');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => $worker->id,
    ]);

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::WorkerAssigned->value
            && $event->tracking['workerId'] === $worker->id;
    });
});

it('returns 422 when accept from non-pending status', function () {
    $workerUser = User::factory()->create(['email' => 'worker-take@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $response->assertUnprocessable();
});

it('returns 403 when user has no worker on accept', function () {
    $regularUser = User::factory()->create(['email' => 'no-worker@example.com']);
    Sanctum::actingAs($regularUser);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $response->assertForbidden();
});

it('returns 403 when booking is assigned to another worker on accept', function () {
    $workerUser = User::factory()->create(['email' => 'worker1@example.com']);
    Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $response->assertForbidden();
});

it('rejects a cleaning booking', function () {
    $workerUser = User::factory()->create(['email' => 'worker-reject@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", [
        'reason' => 'Schedule conflict',
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('pending');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_rejections', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'reason' => 'Schedule conflict',
    ]);
});

it('starts travel for a cleaning booking (sets started_travel_at, status stays worker_assigned)', function () {
    Event::fake([CleaningBookingTrackingUpdated::class]);

    $workerUser = User::factory()->create(['email' => 'worker-travel@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('worker_assigned');
    expect($response->json('data.startedTravelAt'))->not->toBeNull();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
    ]);
    $booking->refresh();
    expect($booking->started_travel_at)->not->toBeNull();

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::WorkerAssigned->value
            && $event->tracking['startedTravelAt'] !== null;
    });
});

it('completes a cleaning booking', function () {
    Event::fake([CleaningBookingTrackingUpdated::class, CleaningOrderAwaitingCustomerCompletion::class]);

    $workerUser = User::factory()->create(['email' => 'worker-complete@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('awaiting_customer_completion');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
    ]);
    expect($response->json('data.workFinishedAt'))->not->toBeNull();

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::AwaitingCustomerCompletion->value
            && $event->tracking['workFinishedAt'] !== null;
    });
});

it('returns 422 when completing booking not in progress', function () {
    $workerUser = User::factory()->create(['email' => 'worker-complete-fail@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete");

    $response->assertUnprocessable();
});

it('cancels a cleaning booking', function () {
    Event::fake([CleaningBookingTrackingUpdated::class]);

    $workerUser = User::factory()->create(['email' => 'worker-cancel@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/cancel", [
        'reason' => 'Emergency',
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('cancelled');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Cancelled->value,
        'cancellation_reason' => 'Emergency',
    ]);

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::Cancelled->value
            && $event->tracking['cancelledAt'] !== null;
    });
});

it('returns 403 when worker tries to cancel booking not assigned to them', function () {
    $workerUser = User::factory()->create(['email' => 'worker-cancel-other@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/cancel");

    $response->assertForbidden();
});

it('rejects a cleaning booking without reason (uses default)', function () {
    $workerUser = User::factory()->create(['email' => 'worker-reject-no-reason@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", []);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('pending');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_rejections', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
    ]);
});

it('allows worker to reject a pending unassigned booking', function () {
    $workerUser = User::factory()->create(['email' => 'worker-reject-pending@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => now()->format('Y-m-d'),
        'scheduled_time' => now()->addHour()->format('H:i'),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", [
        'reason' => 'Cannot take this booking',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'pending');
    $this->assertDatabaseHas('cleaning_booking_worker_rejections', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'reason' => 'Cannot take this booking',
    ]);
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);
});

it('cancels a cleaning booking without reason', function () {
    $workerUser = User::factory()->create(['email' => 'worker-cancel-no-reason@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/cancel", []);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('cancelled');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Cancelled->value,
    ]);
});

it('returns 404 when booking does not exist on accept', function () {
    $workerUser = User::factory()->create(['email' => 'worker-404@example.com']);
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $response = $this->postJson('/api/v1/cleaning-bookings/99999/accept');

    $response->assertNotFound();
});

it('returns 422 when start-travel from invalid status', function () {
    $workerUser = User::factory()->create(['email' => 'worker-travel-invalid@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel");

    $response->assertUnprocessable();
});

it('returns 422 when reject from completed booking', function () {
    $workerUser = User::factory()->create(['email' => 'worker-reject-completed@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", ['reason' => 'Too late']);

    $response->assertUnprocessable();
});

it('returns 422 when cancel from completed booking', function () {
    $workerUser = User::factory()->create(['email' => 'worker-cancel-completed@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/cancel", ['reason' => 'Oops']);

    $response->assertUnprocessable();
});

it('returns 403 when worker tries start-travel on booking not assigned to them', function () {
    $workerUser = User::factory()->create(['email' => 'worker-travel-other@example.com']);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel");

    $response->assertForbidden();
});

it('returns 403 when worker tries complete on booking not assigned to them', function () {
    $workerUser = User::factory()->create(['email' => 'worker-complete-other@example.com']);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete");

    $response->assertForbidden();
});

it('starts work for a cleaning booking (worker_assigned → in_progress)', function () {
    $workerUser = User::factory()->create(['email' => 'worker-startwork@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('in_progress');
    expect($response->json('data.workStartedAt'))->not->toBeNull();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
});

it('returns 422 when start-work from non-worker_assigned status', function () {
    $workerUser = User::factory()->create(['email' => 'worker-startwork-invalid@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work");

    $response->assertUnprocessable();
});

it('returns security code for assigned booking', function () {
    $workerUser = User::factory()->create(['email' => 'worker-security@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code");

    $response->assertOk();
    expect($response->json('data.securityCode'))->toBeString()->toHaveLength(4);
    expect($response->json('data.securityCode'))->toMatch('/^\d{4}$/');

    $record = DB::table('booking_security_codes')
        ->where('booking_id', $booking->id)
        ->where('booking_type', $booking->getMorphClass())
        ->first();

    expect($record)->not->toBeNull();
    expect($record->code_hash)->toBe(hash_hmac('sha256', $response->json('data.securityCode'), (string) config('app.key')));
    expect($record->code)->toBe($record->code_hash);
});

it('returns a valid security code on a second request', function () {
    $workerUser = User::factory()->create(['email' => 'worker-security2@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code");

    $response->assertOk();
    expect($response->json('data.securityCode'))->toMatch('/^\d{4}$/');
    expect($response->json('data.expiresAt'))->not->toBeNull();
});

it('returns 422 for security code when booking is pending', function () {
    $workerUser = User::factory()->create(['email' => 'worker-security-pending@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code");

    $response->assertUnprocessable();
});

it('returns 403 for security code when booking belongs to another worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-security-other@example.com']);
    Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code");

    $response->assertForbidden();
});

it('updates worker location and broadcasts when worker has started travel', function () {
    Event::fake([WorkerLocationUpdated::class]);

    $workerUser = User::factory()->create(['email' => 'worker-location@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'started_travel_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/location", [
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    $response->assertOk();
    expect($response->json('data.ok'))->toBeTrue();
    Event::assertDispatched(WorkerLocationUpdated::class, function (WorkerLocationUpdated $e) use ($booking, $worker) {
        return $e->cleaningBookingId === $booking->id
            && $e->latitude === 33.5138
            && $e->longitude === 36.2765
            && $e->workerId === $worker->id;
    });
});

it('returns 422 when update location before start travel', function () {
    $workerUser = User::factory()->create(['email' => 'worker-location-notravel@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'started_travel_at' => null,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/location", [
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    $response->assertUnprocessable();
});

it('marks worker arrived and broadcasts', function () {
    Event::fake([WorkerArrived::class, CleaningOrderAwaitingStartVerification::class]);

    $workerUser = User::factory()->create(['email' => 'worker-arrive@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'started_travel_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/arrive");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('awaiting_start_verification');
    expect($response->json('data.arrivedAt'))->not->toBeNull();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification->value,
    ]);
    $booking->refresh();
    expect($booking->arrived_at)->not->toBeNull();
    Event::assertDispatched(WorkerArrived::class, function (WorkerArrived $e) use ($booking) {
        return $e->cleaningBookingId === $booking->id;
    });
});

it('returns 422 when arrive before start travel', function () {
    $workerUser = User::factory()->create(['email' => 'worker-arrive-notravel@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'started_travel_at' => null,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/arrive");

    $response->assertUnprocessable();
});
