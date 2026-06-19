<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
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
            'minimum_deposit_amount' => 1000,
            'default_max_negative_balance' => 0,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ], $overrides),
    );
}

function seedUxWorkerDeposit(Worker $worker, float $balance, ?float $minimumRequired = null, ?float $maxNegativeBalance = null): CleaningWorkerDeposit
{
    $settings = CleaningDepositSetting::query()->firstOrCreate([], [
        'minimum_deposit_amount' => 1000,
        'default_max_negative_balance' => 0,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 50,
    ]);

    return CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => $balance,
            'deposited_total' => max($balance, 0),
            'withdrawn_total' => 0,
            'minimum_required' => $minimumRequired ?? $settings->minimum_deposit_amount,
            'max_negative_balance' => $maxNegativeBalance ?? $settings->default_max_negative_balance,
        ],
    );
}

function actingAsIneligibleUxWorker(): Worker
{
    seedUxDepositSettings(['default_max_negative_balance' => 0]);

    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 80,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    seedUxWorkerDeposit($worker, -10, 0, 0);

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
        ->assertJsonPath('code', 'WORKER_NOT_ELIGIBLE_FOR_NEW_REQUESTS')
        ->assertJsonPath('errors.workerEligibility.0.reasonCode', 'deposit_below_allowed_balance')
        ->assertJsonPath('dispatchEligibility.canAcceptNewBookings', false);
});
