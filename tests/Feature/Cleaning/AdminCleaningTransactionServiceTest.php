<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\AdminCleaningTransactionService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 5000,
            'default_max_negative_balance' => 2000,
            'restriction_threshold_percent' => 80,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('returns the financial statistics shown to the admin after selecting a cleaning worker', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 7000,
        'deposited_total' => 10000,
        'withdrawn_total' => 3000,
        'minimum_required' => 5000,
        'max_negative_balance' => 2000,
        'is_active' => true,
    ]);

    createCleaningTransaction($worker, 'debt', 4000, 0, 7000, 11000);
    createCleaningTransaction($worker, 'admin_fee', 2000, 0, 11000, 9000);
    createCleaningTransaction($worker, 'settlement', 1000, 1000, 9000, 8000);

    $snapshot = app(AdminCleaningTransactionService::class)->snapshot($worker->fresh(['deposit']));

    expect($snapshot['currentBalance'])->toBe(7000.0)
        ->and($snapshot['depositedTotal'])->toBe(10000.0)
        ->and($snapshot['withdrawnTotal'])->toBe(3000.0)
        ->and($snapshot['minimumRequired'])->toBe(5000.0)
        ->and($snapshot['maxRefundable'])->toBe(9000.0)
        ->and($snapshot['manualDebtDue'])->toBe(3000.0)
        ->and($snapshot['adminFeeDue'])->toBe(2000.0)
        ->and($snapshot['outstandingAdministrationDue'])->toBe(5000.0)
        ->and($snapshot['totalSettled'])->toBe(1000.0);
});

it('counts completed orders from actual booking records instead of the seeded worker counter', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    $worker->forceFill(['total_completed_jobs' => 120])->save();

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Cancelled->value,
    ]);

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

    expect((int) $worker->fresh()->total_completed_jobs)->toBe(120)
        ->and($snapshot['completedJobs'])->toBe(2);
});

it('validates settlement and refund amounts before creating the transaction', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 3000,
        'deposited_total' => 5000,
        'withdrawn_total' => 2000,
        'minimum_required' => 5000,
        'max_negative_balance' => 2000,
        'is_active' => true,
    ]);

    createCleaningTransaction($worker, 'debt', 4000, 0, 3000, 7000);

    $service = app(AdminCleaningTransactionService::class);
    $freshWorker = $worker->fresh(['deposit']);

    expect($service->validationMessage($freshWorker, 'settlement', 4000))->toBeNull()
        ->and($service->validationMessage($freshWorker, 'settlement', 4000.01))->not->toBeNull()
        ->and($service->validationMessage($freshWorker, 'refund', 5000))->toBeNull()
        ->and($service->validationMessage($freshWorker, 'refund', 5000.01))->not->toBeNull();
});

it('creates a validated transaction through the same domain services used by the ledger', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 5000,
        'max_negative_balance' => 2000,
        'is_active' => true,
    ]);

    $transaction = app(AdminCleaningTransactionService::class)->create(
        worker: $worker->fresh(['deposit']),
        type: 'deposit',
        amount: 5000,
        notes: 'Admin deposit from the page.',
        createdByAdminId: null,
    );

    expect($transaction->type)->toBe('deposit')
        ->and((float) $transaction->amount)->toBe(5000.0)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(5000.0);
});

function createCleaningWorkerForAdminTransaction(): Worker
{
    $user = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker,
    ]);

    return Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'security_deposit_status' => 'active',
    ]);
}

function createCleaningTransaction(
    Worker $worker,
    string $type,
    float $amount,
    float $debtSettledAmount,
    float $balanceBefore,
    float $balanceAfter,
): CleaningDepositTransaction {
    return CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => $type,
        'amount' => $amount,
        'debt_settled_amount' => $debtSettledAmount,
        'balance_before' => $balanceBefore,
        'balance_after' => $balanceAfter,
        'reference' => 'test_'.$type,
    ]);
}
