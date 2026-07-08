<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingCreated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;

it('notifies and broadcasts new cleaning bookings to eligible workers', function (): void {
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 80,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $scheduledAt = now()->addDay()->setTime(15, 0);
    $dayKey = mb_strtolower($scheduledAt->format('l'));
    $neighborhood = CleaningNeighborhood::factory()->create();

    $workerUser = User::factory()->create(['email' => 'eligible-cleaning-worker@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 100,
        'home_address' => (string) $neighborhood->name_ar,
        'home_latitude' => 36.2000,
        'home_longitude' => 37.1500,
        'default_working_hours' => [
            $dayKey => [
                'available' => true,
                'data' => [
                    ['14:00' => '18:00'],
                ],
            ],
        ],
    ]);
    $worker->zones()->create([
        'neighborhood_id' => $neighborhood->id,
        'name' => (string) $neighborhood->name_ar,
        'is_active' => true,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
    ]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => (string) $neighborhood->name_ar,
        'address_latitude' => 36.2100,
        'address_longitude' => 37.1600,
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'base_price' => 45000,
        'addons_total' => 0,
        'total_price' => 45000,
        'number_of_workers' => 1,
    ]);

    (new NotifyEligibleWorkersNewOrderJob((int) $booking->id))->handle();

    Notification::assertSentTo($workerUser, NewOrderRequestNotification::class);

    Event::assertDispatched(CleaningBookingCreated::class, function (CleaningBookingCreated $event) use ($booking, $worker): bool {
        return $event->cleaningBookingId === (int) $booking->id
            && $event->workerId === (int) $worker->id
            && ($event->booking['status'] ?? null) === CleaningBookingStatus::Pending->value;
    });
});
