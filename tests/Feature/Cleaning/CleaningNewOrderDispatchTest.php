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
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningNeighborhood;

it('notifies and broadcasts new cleaning bookings to eligible workers', function (): void {
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 80,
        'allowance_warning_threshold_percent' => 10,
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

it('dispatches a preferred worker booking outside their zones when covered by an allowance limit', function (): void {
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 0,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $scheduledAt = now()->addDay()->setTime(15, 0);
    $dayKey = mb_strtolower($scheduledAt->format('l'));
    $requestedNeighborhood = CleaningNeighborhood::factory()->create(['name_ar' => 'Requested area']);
    $coveredNeighborhood = CleaningNeighborhood::factory()->create(['name_ar' => 'Worker area']);

    $workerUser = User::factory()->create(['email' => 'preferred-cleaning-worker@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 100,
        'home_address' => (string) $coveredNeighborhood->name_ar,
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
        'neighborhood_id' => $coveredNeighborhood->id,
        'name' => (string) $coveredNeighborhood->name_ar,
        'is_active' => true,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => 'preferred_worker',
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
        'neighborhood_id' => $requestedNeighborhood->id,
        'neighborhood_name' => (string) $requestedNeighborhood->name_ar,
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
            && $event->workerId === (int) $worker->id;
    });
});

it('does not dispatch a multi-day event when the worker conflicts on only one event day', function (): void {
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $firstAt = now()->addDays(2)->setTime(10, 0);
    $secondAt = now()->addDays(3)->setTime(10, 0);
    $workingHours = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $workingHours[$day] = [
            'available' => true,
            'data' => [['00:00' => '23:59']],
        ];
    }

    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
        'default_working_hours' => $workingHours,
    ]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    $candidate = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => 'open_count',
        'status' => CleaningBookingStatus::Pending->value,
        'property_type' => 'event_assistance',
        'gender_preference' => 'any',
        'scheduled_date' => $firstAt->toDateString(),
        'scheduled_time' => $firstAt->format('H:i'),
        'estimated_hours' => 4,
        'total_hours' => 4,
        'base_price' => 4000,
        'addons_total' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 4000,
        'number_of_workers' => 1,
    ]);
    foreach ([[$firstAt, 1], [$secondAt, 2]] as [$startsAt, $sequence]) {
        CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $candidate->id,
            'sequence' => $sequence,
            'session_type' => 'event_assistance',
            'calculation_mode' => 'hours',
            'scheduled_date' => $startsAt->toDateString(),
            'scheduled_time' => $startsAt->format('H:i'),
            'duration_hours' => 2,
            'required_workers' => 1,
            'coverage_status' => 'searching',
            'status' => 'scheduled',
            'base_price' => 2000,
            'admin_margin_amount' => 0,
            'total_price' => 2000,
        ]);
    }

    $otherBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => null,
        'scheduled_date' => $secondAt->toDateString(),
        'scheduled_time' => '10:30',
        'estimated_hours' => 1,
        'total_hours' => 1,
    ]);
    $conflictSession = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $otherBooking->id,
        'sequence' => 1,
        'session_type' => 'event_assistance',
        'calculation_mode' => 'hours',
        'scheduled_date' => $secondAt->toDateString(),
        'scheduled_time' => '10:30',
        'duration_hours' => 1,
        'required_workers' => 1,
        'coverage_status' => 'fully_covered',
        'status' => 'worker_assigned',
    ]);
    CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $conflictSession->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
    ]);

    // Ignore creation-observer dispatches; this assertion targets the explicit
    // multi-session eligibility evaluation below.
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    (new NotifyEligibleWorkersNewOrderJob((int) $candidate->id))->handle();

    Notification::assertNotSentTo($workerUser, NewOrderRequestNotification::class);
    Event::assertNotDispatched(CleaningBookingCreated::class);
});


it('dispatches hourly worker bookings only to workers that selected hourly work', function (): void {
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 80,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $scheduledAt = now()->addDay()->setTime(15, 0);
    $dayKey = mb_strtolower($scheduledAt->format('l'));
    $neighborhood = CleaningNeighborhood::factory()->create();

    $makeWorker = function (string $email, array $types) use ($dayKey, $neighborhood): array {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $worker = Worker::factory()->create([
            'user_id' => $user->id,
            'trust_score' => 100,
            'is_active' => true,
            'is_suspended' => false,
            'preferred_work_types' => $types,
            'home_address' => (string) $neighborhood->name_ar,
            'home_latitude' => 36.2000,
            'home_longitude' => 37.1500,
            'default_working_hours' => [
                $dayKey => [
                    'available' => true,
                    'data' => [['14:00' => '18:00']],
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

        return [$user, $worker];
    };

    [$hourlyUser] = $makeWorker('hourly-dispatch-worker@example.com', ['hourly']);
    [$cleaningUser] = $makeWorker('cleaning-dispatch-worker@example.com', ['cleaning']);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
        'property_type' => 'apartment',
        'booking_kind' => 'open_time',
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

    Notification::assertSentTo($hourlyUser, NewOrderRequestNotification::class);
    Notification::assertNotSentTo($cleaningUser, NewOrderRequestNotification::class);
});


it('sends urgent same-day bookings to every eligible worker regardless of radius while excluding invalid locations', function (): void {
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 80,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $scheduledAt = today()->setTime(15, 0);
    $dayKey = mb_strtolower($scheduledAt->format('l'));

    $makeWorker = static function (string $email, ?float $latitude, bool $suspended = false) use ($dayKey): array {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $worker = Worker::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'is_suspended' => $suspended,
            'trust_score' => 100,
            'home_address' => 'Registered worker home',
            'home_latitude' => $latitude,
            'home_longitude' => $latitude === null ? null : 37.15,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['00:00' => '23:59']]],
            ],
        ]);
        CleaningWorkerDeposit::query()->create([
            'worker_id' => $worker->id,
            'current_balance' => 100000,
            'deposited_total' => 100000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 100000,
        ]);

        return [$user, $worker];
    };

    [$nearUser, $nearWorker] = $makeWorker('urgent-near@example.com', 36.2);
    [$farUser, $farWorker] = $makeWorker('urgent-far@example.com', 35.0);
    [$noGpsUser, $noGpsWorker] = $makeWorker('urgent-no-gps@example.com', null);
    [$suspendedUser] = $makeWorker('urgent-suspended@example.com', 36.2, true);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
        'address_latitude' => 36.20,
        'address_longitude' => 37.15,
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'number_of_workers' => 1,
    ]);

    // Isolate the explicit dispatch from any observer-triggered queue activity.
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    (new NotifyEligibleWorkersNewOrderJob((int) $booking->id))->handle();

    foreach ([$nearUser, $farUser] as $user) {
        Notification::assertSentTo($user, NewOrderRequestNotification::class);
    }
    Notification::assertNotSentTo($suspendedUser, NewOrderRequestNotification::class);
    Notification::assertNotSentTo($noGpsUser, NewOrderRequestNotification::class);

    foreach ([$nearWorker, $farWorker] as $worker) {
        Event::assertDispatched(CleaningBookingCreated::class, fn (CleaningBookingCreated $event): bool =>
            $event->cleaningBookingId === (int) $booking->id
                && $event->workerId === (int) $worker->id);
    }

    $dispatch = app(\Modules\Cleaning\Services\CleaningGeographicDispatchService::class);
    expect($dispatch->discoverableBookingIds($farWorker))->toContain((int) $booking->id)
        ->and($dispatch->discoverableBookingIds($noGpsWorker))->not->toContain((int) $booking->id);
});
