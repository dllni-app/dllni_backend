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
        'admin_revenue_withdrawn_total' => 0,
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

it('refunds the full deposit and moves the current commission to withdrawn administration revenue', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 3000,
        'debt_balance' => 0,
        'deposited_total' => 5000,
        'withdrawn_total' => 2000,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'commission',
        'amount' => 700,
        'balance_before' => 3700,
        'balance_after' => 3000,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'test',
    ]);

    $service = app(AdminCleaningTransactionService::class);
    $freshWorker = $worker->fresh(['deposit']);

    expect($service->validationMessage($freshWorker, 'refund', 3000))->toBeNull()
        ->and($service->validationMessage($freshWorker, 'refund', 1000))->not->toBeNull()
        ->and($service->snapshot($freshWorker)['adminCommissionBalance'])->toBe(700.0);

    $transaction = $service->refundFullBalance($freshWorker, 'Close the account.', null);
    $account = $worker->fresh('deposit')->deposit;
    $snapshot = $service->snapshot($worker->fresh(['deposit']));

    expect($transaction->type)->toBe('refund')
        ->and((float) $transaction->amount)->toBe(3000.0)
        ->and((float) $transaction->admin_revenue_withdrawn_amount)->toBe(700.0)
        ->and((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->withdrawn_total)->toBe(5000.0)
        ->and((float) $account->admin_revenue_withdrawn_total)->toBe(700.0)
        ->and($snapshot['adminCommissionBalance'])->toBe(0.0)
        ->and($snapshot['withdrawnAdminRevenueTotal'])->toBe(700.0);
});

it('blocks the full refund while debt exists', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 1000,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    app(AdminCleaningTransactionService::class)->refundFullBalance($worker->fresh(['deposit']), null, null);
})->throws(InvalidArgumentException::class);

it('settles the full debt using the one-click dashboard action', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 18000,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
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
        'admin_revenue_withdrawn_total' => 0,
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
