<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

function actingAsAdminSuspendedWorker(): Worker
{
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
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

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
    ]);

    Sanctum::actingAs($user);

    return $worker->fresh(['user', 'deposit']);
}

function createPendingBookingForSuspensionTest(): CleaningBooking
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

it('blocks an admin suspended worker from homepage orders, pending order lists, and acceptance', function (): void {
    actingAsAdminSuspendedWorker();
    $booking = createPendingBookingForSuspensionTest();

    $homepageResponse = $this->getJson('/api/v1/cleaning/worker/homepage');

    $homepageResponse->assertOk()
        ->assertJsonPath('newOrdersCount', 0)
        ->assertJsonPath('isEligibleForNewRequests', false)
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', false)
        ->assertJsonPath('dispatchEligibility.canAcceptNewBookings', false)
        ->assertJsonPath('dispatchEligibility.reasonCode', 'worker_suspended')
        ->assertJsonPath('commissionCapacityEligibility.canReceiveNewRequests', false)
        ->assertJsonPath('commissionCapacityEligibility.reasonCode', 'worker_suspended');

    $listResponse = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $listResponse->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', false)
        ->assertJsonPath('dispatchEligibility.reasonCode', 'worker_suspended');

    $acceptResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");

    $acceptResponse->assertUnprocessable()
        ->assertJsonPath('dispatchEligibility.canAcceptNewBookings', false)
        ->assertJsonPath('dispatchEligibility.reasonCode', 'worker_suspended')
        ->assertJsonPath('errors.workerEligibility.0.reasonCode', 'worker_suspended');
});

it('does not notify an admin suspended worker about a new order', function (): void {
    $worker = actingAsAdminSuspendedWorker();
    $booking = createPendingBookingForSuspensionTest();

    Notification::fake();

    (new NotifyEligibleWorkersNewOrderJob($booking->id))->handle();

    Notification::assertNothingSent();
    expect($worker->fresh()->is_suspended)->toBeTrue();
});
