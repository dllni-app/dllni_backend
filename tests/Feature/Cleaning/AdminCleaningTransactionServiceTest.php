<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\WorkerDebtService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 50000,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('returns separate deposit debt and capacity values for the admin dashboard', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 20000,
        'debt_balance' => 0,
        'deposited_total' => 30000,
        'withdrawn_total' => 10000,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    app(WorkerDebtService::class)->recordDebt($worker, 35000, 'test_manual_debt', 'Required reason.');
    $snapshot = app(AdminCleaningTransactionService::class)->snapshot($worker->fresh(['deposit']));

    expect($snapshot['depositBalance'])->toBe(0.0)
        ->and($snapshot['debtBalance'])->toBe(15000.0)
        ->and($snapshot['allowedDebtLimit'])->toBe(50000.0)
        ->and($snapshot['remainingDebtCapacity'])->toBe(35000.0)
        ->and($snapshot['outstandingAdministrationDue'])->toBe(15000.0)
        ->and($snapshot['maxRefundable'])->toBe(0.0);
});

it('counts completed orders from actual booking records instead of the seeded worker counter', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    $worker->forceFill(['total_completed_jobs' => 120])->save();

    CleaningBooking::factory()->create(['worker_id' => $worker->id, 'status' => CleaningBookingStatus::Completed->value]);
    CleaningBooking::factory()->create(['worker_id' => $worker->id, 'status' => CleaningBookingStatus::Cancelled->value]);

    $teamBooking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $teamBooking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
        'accepted_at' => now()->subHour(),
        'work_finished_at' => now(),
    ]);

    $snapshot = app(AdminCleaningTransactionService::class)->snapshot($worker->fresh(['deposit']));
    expect((int) $worker->fresh()->total_completed_jobs)->toBe(120)->and($snapshot['completedJobs'])->toBe(2);
});

it('limits refund to the current deposit and blocks it while debt exists', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 3000,
        'debt_balance' => 0,
        'deposited_total' => 5000,
        'withdrawn_total' => 2000,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    $service = app(AdminCleaningTransactionService::class);
    $freshWorker = $worker->fresh(['deposit']);
    expect($service->validationMessage($freshWorker, 'refund', 3000))->toBeNull()
        ->and($service->validationMessage($freshWorker, 'refund', 3000.01))->not->toBeNull();

    app(WorkerDebtService::class)->recordDebt($freshWorker, 4000, 'test_manual_debt', 'Required reason.');
    expect($service->validationMessage($worker->fresh(['deposit']), 'refund', 1))->not->toBeNull();
});

it('settles the full debt using the one-click dashboard action', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 18000,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    $transaction = app(AdminCleaningTransactionService::class)->settleFullDebt($worker->fresh(['deposit']), 'Worker paid the full amount.', null);
    expect($transaction->type)->toBe('settlement')
        ->and((float) $transaction->amount)->toBe(18000.0)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(0.0)
        ->and((float) $worker->fresh('deposit')->deposit->debt_balance)->toBe(0.0);
});

it('requires notes for a manual debt transaction', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    app(AdminCleaningTransactionService::class)->create(
        worker: $worker->fresh(['deposit']),
        type: 'debt',
        amount: 5000,
        notes: null,
        createdByAdminId: null,
    );
})->throws(InvalidArgumentException::class);

function createCleaningWorkerForAdminTransaction(): Worker
{
    $user = User::factory()->create(['module_type' => UserModuleType::CleaningWorker]);

    return Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'security_deposit_status' => 'active',
    ]);
}
