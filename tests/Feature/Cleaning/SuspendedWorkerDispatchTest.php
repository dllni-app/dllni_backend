<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

function actingAsSuspendedCleaningWorker(): Worker
{
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'restriction_threshold_percent' => 100,
            'is_enabled' => false,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );

    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => true,
        'security_deposit_status' => 'suspended',
    ]);

    Sanctum::actingAs($user);

    return $worker;
}

function pendingBookingForSuspendedWorkerTest(): CleaningBooking
{
    return CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
    ]);
}

it('shows the admin suspension state on the worker homepage and reports no new orders', function (): void {
    actingAsSuspendedCleaningWorker();
    pendingBookingForSuspendedWorkerTest();

    $this->getJson('/api/v1/cleaning/worker/homepage')
        ->assertOk()
        ->assertJsonPath('newOrdersCount', 0)
        ->assertJsonPath('isEligibleForNewRequests', false)
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', false)
        ->assertJsonPath('dispatchEligibility.canAcceptNewBookings', false)
        ->assertJsonPath('dispatchEligibility.reasonCode', 'worker_suspended');
});

it('does not return new pending orders to a suspended worker', function (): void {
    actingAsSuspendedCleaningWorker();
    pendingBookingForSuspendedWorkerTest();

    $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('dispatchEligibility.reasonCode', 'worker_suspended');
});

it('prevents a suspended worker from accepting a new booking', function (): void {
    actingAsSuspendedCleaningWorker();
    $booking = pendingBookingForSuspendedWorkerTest();

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept")
        ->assertUnprocessable()
        ->assertJsonPath('errors.workerEligibility.0.reasonCode', 'worker_suspended')
        ->assertJsonPath('dispatchEligibility.canAcceptNewBookings', false);
});
