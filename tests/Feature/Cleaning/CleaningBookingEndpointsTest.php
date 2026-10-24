<?php

declare(strict_types=1);

use App\Enums\WorkerPreferredWorkType;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Services\DepositService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

function authenticateGenericBookingAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

function createCleaningNeighborhoodCoverage(Worker $worker, ?string $name = null): CleaningNeighborhood
{
    static $coverageSequence = 1;

    $fallbackName = sprintf('Coverage Neighborhood %03d', $coverageSequence++);
    $neighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => $name ?? $fallbackName,
        'name_en' => $name ?? $fallbackName,
    ]);

    $worker->zones()->create([
        'neighborhood_id' => $neighborhood->id,
        'name' => $neighborhood->name_ar,
        'is_active' => true,
    ]);

    // Geographic discovery now requires GPS even when a legacy neighborhood is set.
    $worker->forceFill([
        'home_address' => 'QA coverage location, Aleppo',
        'home_latitude' => 36.2000,
        'home_longitude' => 37.1500,
    ])->save();

    return $neighborhood;
}

/**
 * Available offers require an active, funded worker account and a passing
 * trust score. Set those preconditions only in the offer-visibility fixtures,
 * never by bypassing dispatch security checks in production.
 */
function enableEligibleCleaningOfferWorker(Worker $worker): void
{
    // These tests concern offer visibility by work preference, not route
    // geometry. Use the supported per-worker allowance pricing mode so the
    // pending quote does not fail due to unrelated missing test coordinates.
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 0,
    ]);

    $worker->forceFill([
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
    ])->save();

    CleaningWorkerDeposit::query()->updateOrCreate(['worker_id' => $worker->id], [
        'current_balance' => 100000,
        'debt_balance' => 0,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    expect(app(DepositService::class)->isWorkerEligibleForNewRequests($worker->fresh(['deposit'])))
        ->toBeTrue();
}

it('lists cleaning bookings', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->count(3)->create([
        'customer_id' => auth()->id(),
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $this->getJson('/api/v1/cleaning-bookings')->assertForbidden();
    authenticateGenericBookingAdmin();
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
        'propertyType' => 'apartment',
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
        'scheduledTime' => '10:00',
        'numberOfWorkers' => 1,
        'totalHours' => 3,
        'termsAccepted' => true,
    ];

    $this->postJson('/api/v1/cleaning-bookings', $payload)->assertForbidden();
    authenticateGenericBookingAdmin();
    $response = $this->postJson('/api/v1/cleaning-bookings', $payload);

    $response->assertCreated();
    expect($response->json('data.numberOfWorkers'))->toBe(1);
    $this->assertDatabaseHas('cleaning_bookings', [
        'booking_number' => $payload['bookingNumber'],
        'customer_id' => $customer->id,
        'number_of_workers' => 1,
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
        'customer_id' => auth()->id(),
        'billing_policy_id' => $billingPolicy->id,
        'number_of_workers' => 2,
    ]);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();
    authenticateGenericBookingAdmin();
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

    $payload = [
        'propertyDetails' => ['kitchens' => 2, 'cleaning_mode' => 'deep'],
        'termsAccepted' => true,
    ];
    $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", $payload)->assertForbidden();
    authenticateGenericBookingAdmin();
    $response = $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);
    expect(data_get($booking->fresh()->property_details, 'kitchens'))->toBe(2)
        ->and(data_get($booking->fresh()->property_details, 'cleaning_mode'))->toBe('deep');

    $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'status' => CleaningBookingStatus::Completed->value,
        'basePrice' => 1,
        'totalPrice' => 1,
        'workStartedAt' => now()->subHour()->toIso8601String(),
        'workFinishedAt' => now()->toIso8601String(),
    ])->assertUnprocessable();
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
        // The factory randomizes status, while delete intentionally rejects
        // completed, cancelled and already-executing bookings with HTTP 409.
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $this->deleteJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();
    authenticateGenericBookingAdmin();
    $response = $this->deleteJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('cleaning_bookings', ['id' => $booking->id]);
});

it('filters cleaning bookings by forCurrentWorker and scheduledDate', function () {
    $workerUser = User::factory()->create(['email' => 'worker@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
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
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $neighborhood = createCleaningNeighborhoodCoverage($worker, 'Pending Coverage');

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
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(2);
});

it('returns only cleaning available bookings for cleaning preferred workers', function () {
    $workerUser = User::factory()->create(['email' => 'worker-cleaning-preference@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'preferred_work_type' => WorkerPreferredWorkType::Cleaning,
    ]);
    enableEligibleCleaningOfferWorker($worker);
    Sanctum::actingAs($workerUser);

    $neighborhood = createCleaningNeighborhoodCoverage($worker, 'Cleaning Preference');

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
        'property_type' => 'apartment',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'event_assistance',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.propertyType'))->toBe('apartment');
    expect($response->json('data.0.type'))->toBe('cleaning');
});

it('returns only event available bookings for events preferred workers', function () {
    $workerUser = User::factory()->create(['email' => 'worker-events-preference@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'preferred_work_type' => WorkerPreferredWorkType::Events,
    ]);
    enableEligibleCleaningOfferWorker($worker);
    Sanctum::actingAs($workerUser);

    $neighborhood = createCleaningNeighborhoodCoverage($worker, 'Events Preference');

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
        'property_type' => 'apartment',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'event_assistance',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.propertyType'))->toBe('event_assistance');
    expect($response->json('data.0.type'))->toBe('events');
});

it('returns cleaning and event available bookings for both preferred workers', function () {
    $workerUser = User::factory()->create(['email' => 'worker-both-preference@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'preferred_work_type' => WorkerPreferredWorkType::Both,
    ]);
    enableEligibleCleaningOfferWorker($worker);
    Sanctum::actingAs($workerUser);

    $neighborhood = createCleaningNeighborhoodCoverage($worker, 'Both Preference');

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
        'property_type' => 'apartment',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'event_assistance',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('type')->sort()->values()->all())
        ->toBe(['cleaning', 'events']);
});

it('keeps a financially ineligible worker away from new offers without hiding already assigned work', function (): void {
    $workerUser = User::factory()->create(['email' => 'unfunded-offer-privacy@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    Sanctum::actingAs($workerUser);

    // Deliberately leave the financial account absent: an unfunded worker may
    // inspect existing assignments but must not receive new open offers.
    $neighborhood = createCleaningNeighborhoodCoverage($worker, 'Unfunded Privacy');
    $pending = CleaningBooking::factory()->create([
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'neighborhood_id' => $neighborhood->id,
    ]);
    $assigned = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    expect(app(DepositService::class)->isWorkerEligibleForNewRequests($worker->fresh(['deposit'])))
        ->toBeFalse();

    $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', false);

    $bookings = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1');
    $bookings->assertOk()->assertJsonPath('dispatchEligibility.canReceiveNewRequests', false);
    expect(collect($bookings->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->toContain($assigned->id)
        ->not->toContain($pending->id);
});

it('filters cleaning bookings by multiple statuses for current worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-multi-status@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
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
        'status' => CleaningBookingStatus::InProgress,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
    ]);
    CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=in_progress,awaiting_start_verification');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('status')->sort()->values()->all())
        ->toBe([
            CleaningBookingStatus::AwaitingStartVerification->value,
            CleaningBookingStatus::InProgress->value,
        ]);
});

it('filters cleaning bookings by property type', function () {
    authenticateGenericBookingAdmin();
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->create([
        'customer_id' => auth()->id(),
        'billing_policy_id' => $billingPolicy->id,
        'property_type' => 'event_assistance',
    ]);
    CleaningBooking::factory()->create([
        'customer_id' => auth()->id(),
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
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id, 'first_name' => 'Ahmed']);
    Sanctum::actingAs($workerUser);

    $response = $this->getJson('/api/v1/cleaning/worker/profile');

    $response->assertOk();
    expect($response->json('data.id'))->toBe($worker->id);
    expect($response->json('data.firstName'))->toBe('Ahmed');
    expect($response->json('data.preferred_work_type'))->toBe('cleaning');
    expect($response->json('data.preferredWorkTypes'))->toBe(['cleaning', 'events']);
    expect($response->json('data.user.id'))->toBe($workerUser->id);
    expect($response->json('data.user.phone'))->toBe('+963991234567');
});

it('defaults existing style worker records to both preferred work type', function () {
    $workerUser = User::factory()->create(['email' => 'profile-worker-default-preference@example.com']);
    $worker = Worker::query()->create([
        'user_id' => $workerUser->id,
        'first_name' => 'Default Preference Worker',
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->getJson('/api/v1/cleaning/worker/profile');

    $response->assertOk();
    expect($worker->fresh()->preferred_work_type)->toBe(WorkerPreferredWorkType::Both);
    expect($response->json('data.preferred_work_type'))->toBe('cleaning');
    expect($response->json('data.preferredWorkTypes'))->toBe(['cleaning', 'events']);
});

it('returns 403 for worker profile when user has no worker', function () {
    $regularUser = User::factory()->create(['email' => 'no-worker-profile@example.com']);
    Sanctum::actingAs($regularUser);

    $response = $this->getJson('/api/v1/cleaning/worker/profile');

    $response->assertForbidden();
});

it('updates worker profile home location fields', function () {
    $workerUser = User::factory()->create(['email' => 'worker-profile-update@example.com']);
    Worker::factory()->financiallyEligible()->create([
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

it('updates worker birthday from worker profile and returns it in the response', function () {
    $workerUser = User::factory()->create(['email' => 'worker-profile-birthday-update@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.4,
        'home_longitude' => 36.2,
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'birthday' => '1990-03-15',
    ]);

    $response->assertOk();
    expect($response->json('data.birthday'))->toBe('1990-03-15');

    $worker->refresh();
    expect($worker->birthday?->toDateString())->toBe('1990-03-15');
    expect(Illuminate\Support\Facades\DB::table('workers')
        ->where('id', $worker->id)
        ->whereDate('birthday', '1990-03-15')
        ->exists())->toBeTrue();
});

it('updates worker preferred work type from worker profile', function () {
    $workerUser = User::factory()->create(['email' => 'worker-profile-preference-update@example.com']);
    Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'preferred_work_type' => WorkerPreferredWorkType::Both,
        'is_active' => true,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.4,
        'home_longitude' => 36.2,
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'preferred_work_type' => 'cleaning',
    ]);

    $response->assertOk();
    expect($response->json('data.preferred_work_type'))->toBe('cleaning');
});

it('updates multiple worker work types from worker profile', function () {
    $workerUser = User::factory()->create(['email' => 'worker-profile-multi-preference@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'preferred_work_type' => WorkerPreferredWorkType::Cleaning,
        'is_active' => true,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.4,
        'home_longitude' => 36.2,
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'preferred_work_types' => ['cleaning', 'hourly'],
    ]);

    $response->assertOk();
    expect($response->json('data.preferredWorkTypes'))->toBe(['cleaning', 'hourly']);

    $worker->refresh();
    expect($worker->preferredWorkTypes())->toBe(['cleaning', 'hourly']);
});

it('rejects invalid worker preferred work type from worker profile', function () {
    $workerUser = User::factory()->create(['email' => 'worker-profile-preference-invalid@example.com']);
    Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.4,
        'home_longitude' => 36.2,
    ]);
    Sanctum::actingAs($workerUser);

    $response = $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'preferred_work_type' => 'restaurants',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('preferred_work_type');
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
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.55,
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
    expect((bool) $booking->is_pricing_final)->toBeTrue();

    $assignment = Modules\Cleaning\Models\CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $worker->id)
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment?->status?->value)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value);
});

it('fails booking accept when worker home location is missing', function () {
    $workerUser = User::factory()->create(['email' => 'worker-accept-missing-home@example.com']);
    Worker::factory()->financiallyEligible()->create([
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

    $response->assertUnprocessable()->assertJsonValidationErrors(['worker']);
});

it('returns worker homepage stats for authenticated worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-homepage@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
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
        'base_price' => 100,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 100,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
        'base_price' => 50,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
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
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 90,
        'open_disputes_count' => 0,
    ]);
    Sanctum::actingAs($workerUser);

    $neighborhood = createCleaningNeighborhoodCoverage($worker, 'Homepage Coverage');

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
        'base_price' => 200,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 200,
        'scheduled_date' => $today,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => now()->addDays(1),
        'gender_preference' => 'any',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => $neighborhood->name_ar,
        'address_latitude' => 36.2000,
        'address_longitude' => 37.1500,
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
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $today = now()->startOfDay();
    $monday = $today->copy()->startOfWeek(Carbon::MONDAY);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
        'scheduled_date' => $monday->copy()->format('Y-m-d'),
        'base_price' => 1000,
        'addons_total' => 0,
        'travel_fee' => 0,
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
    expect((float) $invoiceSum)->toBeGreaterThanOrEqual(800.0);
    expect((float) $response->json('amountSummary.grossInvoicesAmount'))->toBe(1000.0);
});

it('returns working hours for authenticated worker', function () {
    $workerUser = User::factory()->create(['email' => 'worker-hours@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
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
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
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
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $worker->zones()->createMany([
        ['name' => 'دمشق', 'is_active' => true],
        ['name' => 'ريف دمشق', 'is_active' => true],
    ]);

    $currentNeighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => 'Damascus Coverage',
        'name_en' => 'Damascus Coverage',
    ]);
    $legacyNeighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => 'Rif Coverage',
        'name_en' => 'Rif Coverage',
    ]);
    $replacementNeighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => 'Homs Coverage',
        'name_en' => 'Homs Coverage',
    ]);

    $worker->zones()->delete();
    $worker->zones()->createMany([
        ['neighborhood_id' => $currentNeighborhood->id, 'name' => $currentNeighborhood->name_ar, 'is_active' => true],
        ['neighborhood_id' => $legacyNeighborhood->id, 'name' => $legacyNeighborhood->name_ar, 'is_active' => true],
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

    $payload = [
        'zones' => [
            ['neighborhoodId' => $currentNeighborhood->id, 'isActive' => true],
            ['neighborhoodId' => $replacementNeighborhood->id, 'isActive' => true],
        ],
    ];

    $updateResponse = $this->putJson('/api/v1/cleaning/worker/account/work-areas', $payload);
    $updateResponse->assertOk();
    expect($updateResponse->json('zones'))->toHaveCount(2);

    $this->assertDatabaseHas('worker_zones', [
        'worker_id' => $worker->id,
        'neighborhood_id' => $replacementNeighborhood->id,
        'name' => $replacementNeighborhood->name_ar,
        'is_active' => 1,
    ]);
    $this->assertDatabaseMissing('worker_zones', [
        'worker_id' => $worker->id,
        'neighborhood_id' => $legacyNeighborhood->id,
    ]);

    return;

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
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
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
        'base_price' => 145,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
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
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id, 'is_active' => true]);
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
    Worker::factory()->financiallyEligible()->create([
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

it('allows activating worker account after the worker provides a complete home location', function (): void {
    $workerUser = User::factory()->create(['email' => 'worker-status-home-complete@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => false,
        'home_address' => 'Worker home for dispatch pricing',
        'home_latitude' => 36.1795,
        'home_longitude' => 37.1082,
    ]);
    Sanctum::actingAs($workerUser);

    $this->patchJson('/api/v1/cleaning/worker/account/status', ['isActive' => true])
        ->assertOk()
        ->assertJsonPath('isActive', true);
    expect($worker->fresh()->is_active)->toBeTrue();
});

it('prevents activating an unfunded-home worker through the alternate profile endpoint without partial user writes', function (): void {
    $workerUser = User::factory()->create([
        'email' => 'profile-activation-bypass@example.com',
        'name' => 'Original Worker User',
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => false,
        'home_address' => null,
        'home_latitude' => null,
        'home_longitude' => null,
    ]);
    Sanctum::actingAs($workerUser);

    $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'name' => 'Must Not Be Saved',
        'isActive' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['isActive']);

    expect($worker->fresh()->is_active)->toBeFalse()
        ->and($workerUser->fresh()->name)->toBe('Original Worker User');
});

it('does not allow an active worker to remove their home location by editing the profile', function (): void {
    $workerUser = User::factory()->create(['email' => 'profile-clear-location@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'home_address' => 'Existing Home',
        'home_latitude' => 36.1795,
        'home_longitude' => 37.1082,
    ]);
    Sanctum::actingAs($workerUser);

    $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'homeAddress' => null,
        'homeLatitude' => null,
        'homeLongitude' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors(['isActive']);

    expect($worker->fresh()->is_active)->toBeTrue()
        ->and($worker->fresh()->home_address)->toBe('Existing Home')
        ->and((float) $worker->fresh()->home_latitude)->toBe(36.1795);
});

it('allows a single profile update to set a complete home and activate the worker', function (): void {
    $workerUser = User::factory()->create(['email' => 'profile-complete-activation@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => false,
        'home_address' => null,
        'home_latitude' => null,
        'home_longitude' => null,
    ]);
    Sanctum::actingAs($workerUser);

    $this->putJson('/api/v1/cleaning/worker/account/profile', [
        'isActive' => true,
        'homeAddress' => 'Valid Worker Home',
        'homeLatitude' => 36.1795,
        'homeLongitude' => 37.1082,
    ])->assertOk();

    expect($worker->fresh()->is_active)->toBeTrue()
        ->and($worker->fresh()->home_address)->toBe('Valid Worker Home');
});

describe('cleaning booking work timer', function () {
    beforeEach(function () {
        authenticateGenericBookingAdmin();
        Carbon::setTestNow(Carbon::parse('2026-06-21 12:00:00'));

        $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
            'name' => 'Default',
            'billing_mode' => 'actual_working_time',
            'rules' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('returns remaining work seconds for active in progress booking', function () {
        $booking = CleaningBooking::factory()->create([
            'customer_id' => auth()->id(),
            'billing_policy_id' => $this->billingPolicy->id,
            'status' => CleaningBookingStatus::InProgress,
            'work_started_at' => now()->subMinutes(30),
            'total_hours' => 2,
            'estimated_hours' => 2,
        ]);

        $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

        $response->assertOk();
        expect($response->json('data.shouldShowWorkTimer'))->toBeTrue();
        expect($response->json('data.isWorkOverdue'))->toBeFalse();
        expect($response->json('data.remainingWorkSeconds'))->toBeGreaterThan(0);
        expect($response->json('data.overdueWorkSeconds'))->toBe(0);
        expect($response->json('data.expectedFinishAt'))->not->toBeNull();
        expect($response->json('data.workTimer.source.startField'))->toBe('work_started_at');
        expect($response->json('data.workTimer.source.durationField'))->toBe('total_hours');
    });

    it('returns overdue work seconds when active work exceeds expected duration', function () {
        $booking = CleaningBooking::factory()->create([
            'customer_id' => auth()->id(),
            'billing_policy_id' => $this->billingPolicy->id,
            'status' => CleaningBookingStatus::InProgress,
            'work_started_at' => now()->subHours(3),
            'total_hours' => 2,
            'estimated_hours' => 2,
        ]);

        $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

        $response->assertOk();
        expect($response->json('data.shouldShowWorkTimer'))->toBeTrue();
        expect($response->json('data.isWorkOverdue'))->toBeTrue();
        expect($response->json('data.remainingWorkSeconds'))->toBe(0);
        expect($response->json('data.overdueWorkSeconds'))->toBeGreaterThan(0);
    });

    it('falls back to estimated hours when total hours is zero', function () {
        $booking = CleaningBooking::factory()->create([
            'customer_id' => auth()->id(),
            'billing_policy_id' => $this->billingPolicy->id,
            'status' => CleaningBookingStatus::InProgress,
            'work_started_at' => now()->subMinutes(30),
            'total_hours' => 0,
            'estimated_hours' => 2,
        ]);

        expect((float) $booking->fresh()->estimated_hours)->toBe(2.0);

        $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

        $response->assertOk();
        expect($response->json('data.workTimer'))->not->toBeNull();
        expect($response->json('data.workTimer.source.durationField'))->toBe('estimated_hours');
        expect($response->json('data.expectedFinishAt'))->not->toBeNull();
    });

    it('falls back to arrived at when work started at is missing', function () {
        $booking = CleaningBooking::factory()->create([
            'customer_id' => auth()->id(),
            'billing_policy_id' => $this->billingPolicy->id,
            'status' => CleaningBookingStatus::InProgress,
            'work_started_at' => null,
            'arrived_at' => now()->subMinutes(30),
            'total_hours' => 0,
            'estimated_hours' => 2,
        ]);

        $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

        $response->assertOk();
        expect($response->json('data.workTimer.source.startField'))->toBe('arrived_at');
        expect($response->json('data.expectedFinishAt'))->not->toBeNull();
    });

    it('does not show active overdue state after awaiting customer completion', function () {
        $booking = CleaningBooking::factory()->create([
            'customer_id' => auth()->id(),
            'billing_policy_id' => $this->billingPolicy->id,
            'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
            'work_started_at' => now()->subHours(3),
            'total_hours' => 2,
            'estimated_hours' => 2,
        ]);

        $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

        $response->assertOk();
        expect($response->json('data.shouldShowWorkTimer'))->toBeFalse();
        expect($response->json('data.isWorkOverdue'))->toBeFalse();
        expect($response->json('data.remainingWorkSeconds'))->toBe(0);
        expect($response->json('data.overdueWorkSeconds'))->toBe(0);
        expect($response->json('data.expectedFinishAt'))->not->toBeNull();
    });

    it('returns null-safe work timer payload when start or duration is missing', function () {
        $booking = CleaningBooking::factory()->create([
            'customer_id' => auth()->id(),
            'billing_policy_id' => $this->billingPolicy->id,
            'status' => CleaningBookingStatus::InProgress,
            'work_started_at' => null,
            'arrived_at' => null,
            'total_hours' => 0,
            'estimated_hours' => 0,
        ]);

        $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

        $response->assertOk();
        expect($response->json('data.shouldShowWorkTimer'))->toBeFalse();
        expect($response->json('data.expectedFinishAt'))->toBeNull();
        expect($response->json('data.workTimer.remainingWorkSeconds'))->toBe(0);
        expect($response->json('data.workTimer.overdueWorkSeconds'))->toBe(0);
    });
});
