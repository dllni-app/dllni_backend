<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerDebtService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 50000,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('returns allowance indebtedness and capacity values for the dashboard without adding a deposit', function (): void {
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

    $service = app(AdminCleaningTransactionService::class);
    $service->updateAllowanceLimit($worker, 35000);
    $snapshot = $service->snapshot($worker->fresh(['deposit']));

    expect($snapshot['depositBalance'])->toBe(0.0)
        ->and($snapshot['adminLoanBalance'])->toBe(0.0)
        ->and($snapshot['hasAdminLoan'])->toBeFalse()
        ->and($snapshot['debtBalance'])->toBe(0.0)
        ->and($snapshot['indebtednessBalance'])->toBe(0.0)
        ->and($snapshot['configuredAllowedDebtLimit'])->toBe(35000.0)
        ->and($snapshot['allowedDebtLimit'])->toBe(35000.0)
        ->and($snapshot['remainingDebtCapacity'])->toBe(35000.0)
        ->and($snapshot['outstandingAdministrationDue'])->toBe(0.0)
        ->and($snapshot['maxRefundable'])->toBe(0.0);
});

it('records allowance limit updates as audit-only financial transactions', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 5000,
        'is_active' => true,
    ]);

    $transaction = app(AdminCleaningTransactionService::class)->recordAllowanceLimitUpdate(
        worker: $worker->fresh(['deposit']),
        limit: 12000,
        notes: 'Temporary admin approval.',
        createdByAdminId: null,
    );

    $account = $worker->fresh('deposit')->deposit;

    expect($transaction->type)->toBe('allowance_limit_update')
        ->and($transaction->publicType())->toBe('allowance_limit')
        ->and((float) $transaction->amount)->toBe(12000.0)
        ->and((float) $transaction->balance_before)->toBe(0.0)
        ->and((float) $transaction->balance_after)->toBe(0.0)
        ->and((float) $transaction->debt_balance_before)->toBe(0.0)
        ->and((float) $transaction->debt_balance_after)->toBe(0.0)
        ->and($transaction->reference)->toStartWith(CleaningDepositTransaction::ALLOWANCE_LIMIT_UPDATE_REFERENCE_PREFIX)
        ->and((float) $account->max_negative_balance)->toBe(12000.0)
        ->and((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->debt_balance)->toBe(0.0)
        ->and((float) $account->deposited_total)->toBe(0.0)
        ->and((float) $account->withdrawn_total)->toBe(0.0);
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

it('prevents changing the allowance limit while the worker has a deposit balance', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 1000,
        'debt_balance' => 0,
        'deposited_total' => 1000,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
        'is_active' => true,
    ]);

    app(AdminCleaningTransactionService::class)->updateAllowanceLimit($worker->fresh(['deposit']), 500);
})->throws(InvalidArgumentException::class);

it('prevents recording an allowance limit transaction while the worker has a deposit balance', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 1000,
        'debt_balance' => 0,
        'deposited_total' => 1000,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 500,
        'is_active' => true,
    ]);

    app(AdminCleaningTransactionService::class)->recordAllowanceLimitUpdate($worker->fresh(['deposit']), 500, null, null);
})->throws(InvalidArgumentException::class);

it('prevents lowering the allowance limit below current indebtedness', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 800,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 1000,
        'is_active' => true,
    ]);

    app(AdminCleaningTransactionService::class)->updateAllowanceLimit($worker->fresh(['deposit']), 500);
})->throws(InvalidArgumentException::class);

it('prevents recording an allowance limit below current indebtedness', function (): void {
    $worker = createCleaningWorkerForAdminTransaction();
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 800,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 1000,
        'is_active' => true,
    ]);

    app(AdminCleaningTransactionService::class)->recordAllowanceLimitUpdate($worker->fresh(['deposit']), 500, null, null);
})->throws(InvalidArgumentException::class);

it('keeps legacy administration loan records readable and recovers them before refunding the remainder', function (): void {
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

    $service = app(AdminCleaningTransactionService::class);
    app(WorkerDebtService::class)->recordDebt($worker, 2000, WorkerDebtService::ADMIN_LOAN_REFERENCE, 'Legacy administration-funded opening balance.', null);
    app(DepositService::class)->recordDeposit($worker->fresh(['deposit']), 3000, 'worker_cash_deposit');

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);
    app(DepositService::class)->recordAdminFeeDebit($worker->fresh(['deposit']), $booking, 700);

    $freshWorker = $worker->fresh(['deposit']);
    $snapshot = $service->snapshot($freshWorker);

    expect($snapshot['depositBalance'])->toBe(2300.0)
        ->and($snapshot['adminLoanBalance'])->toBe(0.0)
        ->and($snapshot['adminCommissionBalance'])->toBe(700.0)
        ->and($snapshot['maxRefundable'])->toBe(2300.0)
        ->and($service->validationMessage($freshWorker, 'refund', 2300))->toBeNull()
        ->and($service->validationMessage($freshWorker, 'refund', 1000))->not->toBeNull();

    $transaction = $service->refundFullBalance($freshWorker, 'Close the account.', null);
    $account = $worker->fresh('deposit')->deposit;
    $after = $service->snapshot($worker->fresh(['deposit']));

    expect($transaction->type)->toBe('refund')
        ->and((float) $transaction->amount)->toBe(2300.0)
        ->and((float) $transaction->debt_settled_amount)->toBe(0.0)
        ->and((float) $transaction->admin_revenue_withdrawn_amount)->toBe(700.0)
        ->and((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->withdrawn_total)->toBe(2300.0)
        ->and((float) $account->admin_revenue_withdrawn_total)->toBe(700.0)
        ->and($after['adminLoanBalance'])->toBe(0.0)
        ->and($after['adminCommissionBalance'])->toBe(0.0)
        ->and($after['withdrawnAdminRevenueTotal'])->toBe(700.0);
});

it('blocks the full refund while indebtedness exists', function (): void {
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

it('settles the full indebtedness using the one-click dashboard action', function (): void {
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

it('blocks administration loan transactions from the dashboard service', function (): void {
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
        notes: 'Administration-funded opening balance.',
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
