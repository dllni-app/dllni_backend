<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists cleaning bookings', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->count(3)->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a cleaning booking', function () {
    $customer = User::factory()->create(['email' => 'customer@example.com']);
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $payload = [
        'customerId' => $customer->id,
        'billingPolicyId' => $billingPolicy->id,
        'bookingNumber' => 'CLN-'.mb_strtoupper(Str::random(6)),
        'status' => CleaningBookingStatus::Pending->value,
        'propertyType' => 'apartment',
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
        'scheduledTime' => '10:00',
        'totalHours' => 3,
        'basePrice' => 80,
        'travelFee' => 10,
        'totalPrice' => 90,
        'termsAccepted' => true,
    ];

    $response = $this->postJson('/api/v1/cleaning-bookings', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('cleaning_bookings', [
        'booking_number' => $payload['bookingNumber'],
        'customer_id' => $customer->id,
    ]);
});

it('shows a cleaning booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($booking->id);
    expect($response->json('data.bookingNumber'))->toBe($booking->booking_number);
});

it('updates a cleaning booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $response = $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'customerId' => $booking->customer_id,
        'billingPolicyId' => $billingPolicy->id,
        'bookingNumber' => $booking->booking_number,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'propertyType' => $booking->property_type,
        'scheduledDate' => $booking->scheduled_date->format('Y-m-d'),
        'scheduledTime' => $booking->scheduled_time,
        'totalHours' => (float) $booking->total_hours,
        'basePrice' => (float) $booking->base_price,
        'travelFee' => (float) $booking->travel_fee,
        'totalPrice' => (float) $booking->total_price,
        'termsAccepted' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
    ]);
});

it('deletes a cleaning booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->deleteJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('cleaning_bookings', ['id' => $booking->id]);
});

it('filters cleaning bookings by forCurrentWorker and scheduledDate', function () {
    $workerUser = User::factory()->create(['email' => 'worker@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $today = now()->format('Y-m-d');
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'scheduled_date' => $today,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'scheduled_date' => now()->addDays(5),
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[scheduledDate]={$today}");

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(1);
    expect($response->json('data.0.scheduledDate'))->toBe($today);
});

it('returns pending unassigned bookings for worker when forCurrentWorker and status pending', function () {
    $workerUser = User::factory()->create(['email' => 'worker-new-requests@example.com']);
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(2);
});

it('returns worker profile when user has worker', function () {
    $workerUser = User::factory()->create(['email' => 'profile-worker@example.com', 'phone' => '+963991234567']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id, 'first_name' => 'Ahmed']);
    Sanctum::actingAs($workerUser);

    $response = $this->getJson('/api/v1/cleaning/worker/profile');

    $response->assertOk();
    expect($response->json('data.id'))->toBe($worker->id);
    expect($response->json('data.firstName'))->toBe('Ahmed');
    expect($response->json('data.user.id'))->toBe($workerUser->id);
    expect($response->json('data.user.phone'))->toBe('+963991234567');
});

it('returns 403 for worker profile when user has no worker', function () {
    $regularUser = User::factory()->create(['email' => 'no-worker-profile@example.com']);
    Sanctum::actingAs($regularUser);

    $response = $this->getJson('/api/v1/cleaning/worker/profile');

    $response->assertForbidden();
});

it('returns worker homepage stats for authenticated worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-homepage@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
        'total_price' => 100,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
        'total_price' => 50,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);

    $response = $this->getJson('/api/v1/cleaning/worker/homepage');

    $response->assertOk();
    expect($response->json('totalBookings'))->toBe(3);
    expect($response->json('completedCount'))->toBe(2);
    expect($response->json('pendingCount'))->toBeGreaterThanOrEqual(0);
    expect((float) $response->json('totalEarnings'))->toBe(150.0);
});

it('returns zeros for worker homepage when user has no worker', function () {
    $regularUser = User::factory()->create(['email' => 'nobody@example.com']);
    Sanctum::actingAs($regularUser);

    $response = $this->getJson('/api/v1/cleaning/worker/homepage');

    $response->assertOk();
    expect($response->json('date'))->toBe(now()->format('Y-m-d'));
    expect($response->json('totalBookings'))->toBe(0);
    expect($response->json('todayCount'))->toBe(0);
    expect($response->json('totalEarnings'))->toBe(0);
    expect($response->json('todayEarnings'))->toBe(0);
    expect($response->json('earningsChangePercent'))->toBe(0);
    expect($response->json('newOrdersCount'))->toBe(0);
    expect($response->json('pendingExtensionRequestsCount'))->toBe(0);
});

it('returns worker homepage with todayEarnings newOrdersCount and pendingExtensionRequestsCount', function () {
    $workerUser = User::factory()->create(['email' => 'worker-homepage-extended@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $today = now()->format('Y-m-d');
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
        'total_price' => 200,
        'scheduled_date' => $today,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => now()->addDays(1),
    ]);

    $bookingForWarning = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);
    Modules\Cleaning\Models\CleaningTimeWarning::create([
        'booking_id' => $bookingForWarning->id,
        'booking_type' => 'cleaning_booking',
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/cleaning/worker/homepage');

    $response->assertOk();
    expect($response->json('date'))->toBe(now()->format('Y-m-d'));
    expect((float) $response->json('todayEarnings'))->toBe(200.0);
    expect((float) $response->json('earningsChangePercent'))->toBe(100.0);
    expect($response->json('newOrdersCount'))->toBeGreaterThanOrEqual(1);
    expect($response->json('pendingExtensionRequestsCount'))->toBe(1);
});
