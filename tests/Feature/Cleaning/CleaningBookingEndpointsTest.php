<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\CleaningFinancialSetting;
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
        'numberOfWorkers' => 3,
        'totalHours' => 3,
        'basePrice' => 80,
        'travelFee' => 10,
        'totalPrice' => 90,
        'termsAccepted' => true,
    ];

    $response = $this->postJson('/api/v1/cleaning-bookings', $payload);

    $response->assertCreated();
    expect($response->json('data.numberOfWorkers'))->toBe(3);
    $this->assertDatabaseHas('cleaning_bookings', [
        'booking_number' => $payload['bookingNumber'],
        'customer_id' => $customer->id,
        'number_of_workers' => 3,
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
        'number_of_workers' => 2,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($booking->id);
    expect($response->json('data.bookingNumber'))->toBe($booking->booking_number);
    expect($response->json('data.numberOfWorkers'))->toBe(2);
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
        'numberOfWorkers' => 4,
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
        'number_of_workers' => 4,
    ]);
    expect($response->json('data.numberOfWorkers'))->toBe(4);
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
        'gender_preference' => 'any',
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(2);
});

it('filters cleaning bookings by property type', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
        'property_type' => 'event_assistance',
    ]);
    CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
        'property_type' => 'apartment',
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[propertyType]=event_assistance');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.propertyType'))->toBe('event_assistance');
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

it('updates worker profile home location fields', function () {
    $workerUser = User::factory()->create(['email' => 'worker-profile-update@example.com']);
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'home_address' => 'Old Home',
        'home_latitude' => 33.4,
        'home_longitude' => 36.2,
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'homeAddress' => 'Damascus, Al Mazzeh',
        'homeLatitude' => 33.5138,
        'homeLongitude' => 36.2765,
    ]);

    $response->assertOk();
    expect($response->json('data.homeAddress'))->toBe('Damascus, Al Mazzeh');
    expect((float) $response->json('data.homeLatitude'))->toBe(33.5138);
    expect((float) $response->json('data.homeLongitude'))->toBe(36.2765);
});

it('finalizes provisional pricing when worker accepts booking', function () {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
        ]
    );

    $workerUser = User::factory()->create(['email' => 'worker-accept-finalize@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.3,
        'is_active' => true,
    ]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'address_latitude' => 33.5,
        'address_longitude' => 36.3,
        'base_price' => 920,
        'addons_total' => 0,
        'travel_fee' => 0,
        'travel_distance_km' => null,
        'admin_margin_amount' => 0,
        'total_price' => 920,
        'is_pricing_final' => false,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $response->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::WorkerAssigned->value)
        ->assertJsonPath('data.isPricingFinal', true);

    $booking->refresh();
    expect($booking->worker_id)->toBe($worker->id);
    expect((float) $booking->travel_distance_km)->toBe(11.119);
    expect((float) $booking->travel_fee)->toBe(111.19);
    expect((float) $booking->admin_margin_amount)->toBe(103.12);
    expect((float) $booking->total_price)->toBe(1134.31);
    expect((bool) $booking->is_pricing_final)->toBeTrue();
});

it('fails booking accept when worker home location is missing', function () {
    $workerUser = User::factory()->create(['email' => 'worker-accept-missing-home@example.com']);
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => null,
        'home_latitude' => null,
        'home_longitude' => null,
        'is_active' => true,
    ]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'address_latitude' => 33.5,
        'address_longitude' => 36.3,
        'is_pricing_final' => false,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $response->assertUnprocessable()->assertJsonValidationErrors(['status']);
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
    expect((float) $response->json('amountSummary.workerAmount'))->toBe(0.0);
    expect((float) $response->json('amountSummary.adminAmount'))->toBe(0.0);
    expect((float) $response->json('amountSummary.grossInvoicesAmount'))->toBe(0.0);
    expect($response->json('bookingsWeeklyChart'))->toBeArray()->toHaveCount(7);
    expect($response->json('invoicesFourWeeksChart'))->toBeArray()->toHaveCount(4);
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
        'gender_preference' => 'any',
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
    expect((float) $response->json('amountSummary.workerAmount'))->toBe(200.0);
    expect((float) $response->json('amountSummary.adminAmount'))->toBe(0.0);
    expect((float) $response->json('amountSummary.grossInvoicesAmount'))->toBe(200.0);
    expect($response->json('bookingsWeeklyChart'))->toBeArray()->toHaveCount(7);
    expect($response->json('invoicesFourWeeksChart'))->toBeArray()->toHaveCount(4);
});

it('returns worker homepage chart and amount summary blocks for the owner dashboard screen', function () {
    $workerUser = User::factory()->create(['email' => 'worker-homepage-owner-dashboard@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $today = now()->startOfDay();
    $monday = $today->copy()->startOfWeek(Carbon\Carbon::MONDAY);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
        'scheduled_date' => $monday->copy()->format('Y-m-d'),
        'total_price' => 1000,
        'admin_margin_amount' => 200,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => $monday->copy()->addDay()->format('Y-m-d'),
    ]);

    $response = $this->getJson('/api/v1/cleaning/worker/homepage');

    $response->assertOk();
    expect((float) $response->json('amountSummary.workerAmount'))->toBe(800.0);
    expect((float) $response->json('amountSummary.adminAmount'))->toBe(200.0);
    expect((float) $response->json('amountSummary.grossInvoicesAmount'))->toBe(1000.0);

    $bookingsWeeklyChart = $response->json('bookingsWeeklyChart');
    expect($bookingsWeeklyChart)->toBeArray()->toHaveCount(7);
    expect((int) $bookingsWeeklyChart[0]['bookingsCount'])->toBeGreaterThanOrEqual(1);

    $invoicesChart = $response->json('invoicesFourWeeksChart');
    expect($invoicesChart)->toBeArray()->toHaveCount(4);
    $invoiceSum = collect($invoicesChart)->sum(fn ($item) => (float) ($item['invoiceAmount'] ?? 0));
    expect((float) $invoiceSum)->toBeGreaterThanOrEqual(1000.0);
});

it('returns working hours for authenticated worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-hours@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'default_working_hours' => [
            'sunday' => ['available' => true, 'data' => [['09:00' => '17:00']]],
            'monday' => ['available' => false, 'data' => []],
            'tuesday' => ['available' => true, 'data' => [['10:00' => '18:00']]],
            'wednesday' => ['available' => false, 'data' => []],
            'thursday' => ['available' => false, 'data' => []],
            'friday' => ['available' => false, 'data' => []],
            'saturday' => ['available' => false, 'data' => []],
        ],
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->getJson('/api/v1/cleaning/worker/working-hours');

    $response->assertOk();
    $hours = $response->json('data.defaultWorkingHours');
    expect($hours)->toHaveKeys(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);
    expect($hours['sunday'])->toEqual(['available' => true, 'data' => [['09:00' => '17:00']]]);
    expect($hours['monday'])->toEqual(['available' => false, 'data' => []]);
    expect($hours['tuesday'])->toEqual(['available' => true, 'data' => [['10:00' => '18:00']]]);
});

it('returns 403 for working hours when user has no worker', function () {
    $regularUser = User::factory()->create(['email' => 'no-worker-hours@example.com']);
    Sanctum::actingAs($regularUser);

    $response = $this->getJson('/api/v1/cleaning/worker/working-hours');

    $response->assertForbidden();
});

it('updates working hours for authenticated worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-update-hours@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $payload = [
        'defaultWorkingHours' => [
            'sunday' => ['available' => true, 'data' => [['09:00' => '23:00']]],
            'monday' => ['available' => true, 'data' => [['09:00' => '13:00'], ['15:00' => '23:00']]],
            'tuesday' => ['available' => true, 'data' => [['09:00' => '23:00']]],
            'wednesday' => ['available' => true, 'data' => [['09:00' => '23:00']]],
            'thursday' => ['available' => true, 'data' => [['09:00' => '23:00']]],
            'friday' => ['available' => true, 'data' => [['09:00' => '23:00']]],
            'saturday' => ['available' => false, 'data' => []],
        ],
    ];

    $response = $this->putJson('/api/v1/cleaning/worker/working-hours', $payload);

    $response->assertOk();
    expect($response->json('data.defaultWorkingHours'))->toEqual($payload['defaultWorkingHours']);
    $worker->refresh();
    expect($worker->default_working_hours)->toEqual($payload['defaultWorkingHours']);
});

it('returns worker account work areas and updates them', function () {
    $workerUser = User::factory()->create(['email' => 'worker-areas@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $worker->zones()->createMany([
        ['name' => 'دمشق', 'is_active' => true],
        ['name' => 'ريف دمشق', 'is_active' => true],
    ]);

    $showResponse = $this->getJson('/api/v1/cleaning/worker/account/work-areas');
    $showResponse->assertOk();
    expect($showResponse->json('zones'))->toHaveCount(2);

    $payload = [
        'zones' => [
            ['name' => 'دمشق', 'isActive' => true],
            ['name' => 'حمص', 'isActive' => true],
        ],
    ];

    $updateResponse = $this->putJson('/api/v1/cleaning/worker/account/work-areas', $payload);
    $updateResponse->assertOk();
    expect($updateResponse->json('zones'))->toHaveCount(2);

    $this->assertDatabaseHas('worker_zones', [
        'worker_id' => $worker->id,
        'name' => 'حمص',
        'is_active' => 1,
    ]);
    $this->assertDatabaseMissing('worker_zones', [
        'worker_id' => $worker->id,
        'name' => 'ريف دمشق',
    ]);
});

it('returns worker account transactions for authenticated worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-transactions@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $customer = User::factory()->create(['email' => 'customer-transactions@example.com']);
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'customer_id' => $customer->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
        'total_price' => 145,
    ]);

    $response = $this->getJson('/api/v1/cleaning/worker/account/transactions');
    $response->assertOk();
    expect($response->json('summary.totalTransactions'))->toBe(1);
    expect((float) $response->json('summary.totalEarnings'))->toBe(145.0);
    expect($response->json('data.0.customer.id'))->toBe($customer->id);
});

it('returns worker account status and updates active flag', function () {
    $workerUser = User::factory()->create(['email' => 'worker-status@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id, 'is_active' => true]);
    Sanctum::actingAs($workerUser);

    $showResponse = $this->getJson('/api/v1/cleaning/worker/account/status');
    $showResponse->assertOk();
    expect($showResponse->json('isActive'))->toBeTrue();

    $updateResponse = $this->patchJson('/api/v1/cleaning/worker/account/status', [
        'isActive' => false,
    ]);
    $updateResponse->assertOk();
    expect($updateResponse->json('isActive'))->toBeFalse();

    $worker->refresh();
    expect($worker->is_active)->toBeFalse();
});

it('rejects activating worker account status without home location', function () {
    $workerUser = User::factory()->create(['email' => 'worker-status-home-required@example.com']);
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => false,
        'home_address' => null,
        'home_latitude' => null,
        'home_longitude' => null,
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->patchJson('/api/v1/cleaning/worker/account/status', [
        'isActive' => true,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['isActive']);
});
