<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ],
    );
});

it('only returns a previous worker when the worker is free for every requested event session', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 100000,
            'debt_balance' => 0,
            'deposited_total' => 100000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 100000,
        ],
    );

    CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
        'scheduled_date' => now(config('app.timezone'))->subDays(10)->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 2,
    ]);

    $firstDate = now(config('app.timezone'))->addDays(2)->toDateString();
    $secondDate = now(config('app.timezone'))->addDays(4)->toDateString();

    CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => $secondDate,
        'scheduled_time' => '18:00',
        'estimated_hours' => 3,
        'total_hours' => 3,
    ]);

    Sanctum::actingAs($customer);

    $singleDayQuery = http_build_query([
        'propertyType' => 'event_assistance',
        'scheduledDate' => $firstDate,
        'scheduledTime' => '10:00',
        'durationHours' => 2,
    ]);

    $this->getJson('/api/v1/user/cleaning/orders/previous-workers?'.$singleDayQuery)
        ->assertOk()
        ->assertJsonPath('workers.0.workerId', $worker->id);

    $multiDayQuery = http_build_query([
        'propertyType' => 'event_assistance',
        'schedule' => [
            'mode' => 'multi_day',
            'sessions' => [
                ['date' => $firstDate, 'time' => '10:00', 'hours' => 2],
                ['date' => $secondDate, 'time' => '18:00', 'hours' => 3],
            ],
        ],
    ]);

    $this->getJson('/api/v1/user/cleaning/orders/previous-workers?'.$multiDayQuery)
        ->assertOk()
        ->assertJsonCount(0, 'workers');
});

it('rejects a malformed multi-session previous-worker schedule', function (): void {
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $date = now(config('app.timezone'))->addDays(2)->toDateString();
    $query = http_build_query([
        'propertyType' => 'event_assistance',
        'schedule' => [
            'mode' => 'single_day',
            'sessions' => [
                ['date' => $date, 'time' => '10:00', 'hours' => 2],
                ['date' => $date, 'time' => '10:00', 'hours' => 3],
            ],
        ],
    ]);

    $this->getJson('/api/v1/user/cleaning/orders/previous-workers?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'schedule.mode',
            'schedule.sessions.1.time',
        ]);
});
