<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

function seedPreviousWorkerScheduleConflictSettings(): void
{
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 50000,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ],
    );
}

function seedPreviousWorkerScheduleConflictDeposit(Worker $worker): void
{
    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 100000,
            'debt_balance' => 0,
            'deposited_total' => 100000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 50000,
            'is_active' => true,
        ],
    );
}

function createPreviousWorkerHistoryForScheduleConflict(User $customer, Worker $worker): void
{
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'assignment_mode' => 'open_count',
        'status' => CleaningBookingStatus::Completed,
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => '12:00',
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
        'accepted_at' => now()->subDays(2),
        'work_finished_at' => now()->subDay(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 100,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 100,
        'currency' => 'SYP',
    ]);
}

it('does not return a previous worker whose accepted booking overlaps the requested service time', function (): void {
    seedPreviousWorkerScheduleConflictSettings();

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $scheduledDate = now()->addDay();
    $dayKey = mb_strtolower($scheduledDate->format('l'));
    $workingHours = [
        $dayKey => ['available' => true, 'data' => [['08:00' => '18:00']]],
    ];

    $conflictingWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => $workingHours,
    ]);
    $availableWorker = Worker::factory()->create([
        'trust_score' => 80,
        'default_working_hours' => $workingHours,
    ]);

    foreach ([$conflictingWorker, $availableWorker] as $worker) {
        seedPreviousWorkerScheduleConflictDeposit($worker);
        createPreviousWorkerHistoryForScheduleConflict($customer, $worker);
    }

    $acceptedBooking = CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => $scheduledDate->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 2,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $acceptedBooking->id,
        'worker_id' => $conflictingWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 100,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 100,
        'currency' => 'SYP',
    ]);

    $query = http_build_query([
        'propertyType' => 'house',
        'scheduledDate' => $scheduledDate->toDateString(),
        'scheduledTime' => '11:00',
        'durationHours' => 1,
    ]);

    $response = $this->getJson('/api/v1/user/cleaning/orders/previous-workers?'.$query);

    $response->assertOk();
    $workerIds = collect($response->json('workers'))->pluck('workerId')->all();

    expect($workerIds)
        ->toContain($availableWorker->id)
        ->not->toContain($conflictingWorker->id);
});
