<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

function seedUxDepositSettings(array $overrides = []): CleaningDepositSetting
{
    return CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        array_merge([
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ], $overrides),
    );
}

function seedUxFinancialSettings(array $overrides = []): CleaningFinancialSetting
{
    return CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        array_merge([
            'default_commission_rate' => 10.00,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'vat_rate' => 0.00,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0.00,
            'travel_per_km' => 0.00,
            'travel_distance_start_point' => 'worker_home',
        ], $overrides),
    );
}

function seedUxWorkerDeposit(Worker $worker, float $balance, float $debtBalance = 0, float $maxNegativeBalance = 0): CleaningWorkerDeposit
{
    return CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => max(0, $balance),
            'debt_balance' => max(0, $debtBalance),
            'deposited_total' => max($balance, 0),
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => max(0, $maxNegativeBalance),
        ],
    );
}

function actingAsIneligibleUxWorker(): Worker
{
    seedUxDepositSettings();

    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 80,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    seedUxWorkerDeposit($worker, balance: 0, debtBalance: 10, maxNegativeBalance: 0);

    Sanctum::actingAs($user);

    return $worker->fresh(['deposit']);
}

it('exposes structured dispatch eligibility on worker account status', function (): void {
    actingAsIneligibleUxWorker();

    $response = $this->getJson('/api/v1/cleaning/worker/account/status');

    $response->assertOk()
        ->assertJsonPath('isEligibleForNewRequests', false)
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', false)
        ->assertJsonPath('dispatchEligibility.canAcceptNewBookings', false)
        ->assertJsonPath('dispatchEligibility.reasonCode', 'deposit_below_allowed_balance');
});

it('sets homepage new orders count to zero for ineligible workers', function (): void {
    actingAsIneligibleUxWorker();

    CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
    ]);

    $response = $this->getJson('/api/v1/cleaning/worker/homepage');

    $response->assertOk()
        ->assertJsonPath('newOrdersCount', 0)
        ->assertJsonPath('isEligibleForNewRequests', false)
        ->assertJsonPath('dispatchEligibility.reasonCode', 'deposit_below_allowed_balance');
});

it('hides pending new requests from ineligible workers in the current worker list', function (): void {
    actingAsIneligibleUxWorker();

    CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('dispatchEligibility.reasonCode', 'deposit_below_allowed_balance');
});

it('hides and warns about pending new requests when commission capacity is insufficient', function (): void {
    seedUxDepositSettings();
    seedUxFinancialSettings();

    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 80,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
    ]);
    seedUxWorkerDeposit($worker, balance: 1000, debtBalance: 0, maxNegativeBalance: 0);

    $acceptedBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'base_price' => 10000,
        'addons_total' => 0,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);
    $acceptedBooking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 900,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    $newBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'base_price' => 20000,
        'addons_total' => 0,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);

    Sanctum::actingAs($user);

    $homepageResponse = $this->getJson('/api/v1/cleaning/worker/homepage');

    $homepageResponse->assertOk()
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', true)
        ->assertJsonPath('commissionCapacityEligibility.canReceiveNewRequests', false)
        ->assertJsonPath('commissionCapacityEligibility.reasonCode', 'insufficient_commission_capacity')
        ->assertJsonPath('commissionCapacityEligibility.blockedNewOrdersCount', 1)
        ->assertJsonPath('newOrdersCount', 0);

    $listResponse = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $listResponse->assertOk()
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', true);

    expect(collect($listResponse->json('data'))->contains(fn (array $booking): bool => $booking['id'] === $newBooking->id))->toBeFalse();

    $acceptResponse = $this->postJson("/api/v1/cleaning-bookings/{$newBooking->id}/accept");

    $acceptResponse->assertUnprocessable()
        ->assertJsonPath('code', 'WORKER_NOT_ELIGIBLE_FOR_BOOKING_COMMISSION')
        ->assertJsonPath('errors.workerEligibility.0.reasonCode', 'insufficient_commission_capacity');
});

it('returns a structured business error when an ineligible worker tries to accept a booking', function (): void {
    actingAsIneligibleUxWorker();

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $response->assertUnprocessable()
        ->assertJsonPath('code', 'WORKER_NOT_ELIGIBLE_FOR_BOOKING_COMMISSION')
        ->assertJsonPath('errors.workerEligibility.0.reasonCode', 'deposit_below_allowed_balance')
        ->assertJsonPath('dispatchEligibility.canAcceptNewBookings', false);
});
