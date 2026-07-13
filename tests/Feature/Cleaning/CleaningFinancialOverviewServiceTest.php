<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Services\CleaningFinancialOverviewService;

it('uses canonical cleaning account and ledger totals for the financial cards', function (): void {
    $activeCleaningWorker = createOverviewWorker(
        UserModuleType::CleaningWorker,
        isActive: true,
        isSuspended: false,
        depositStatus: 'active',
    );

    $restrictedCleaningWorker = createOverviewWorker(
        UserModuleType::CleaningWorker,
        isActive: true,
        isSuspended: false,
        depositStatus: 'insufficient_balance',
    );

    $nonCleaningWorker = createOverviewWorker(
        UserModuleType::DeliveryDriver,
        isActive: true,
        isSuspended: false,
        depositStatus: 'active',
    );

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $activeCleaningWorker->id,
        'current_balance' => 450000,
        'deposited_total' => 1000000,
        'withdrawn_total' => 100000,
        'minimum_required' => 50000,
        'max_negative_balance' => 0,
        'is_active' => true,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $nonCleaningWorker->id,
        'current_balance' => 7000000,
        'deposited_total' => 9000000,
        'withdrawn_total' => 2000000,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
        'is_active' => true,
    ]);

    createOverviewTransaction($activeCleaningWorker, 'debt', 400000, 0, 0, 400000, 'admin_debt');
    createOverviewTransaction(
        $activeCleaningWorker,
        'debt',
        100000,
        0,
        400000,
        300000,
        CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'cleaning-worker',
    );
    createOverviewTransaction($activeCleaningWorker, 'settlement', 50000, 50000, 300000, 250000, 'admin_settlement');

    createOverviewTransaction($nonCleaningWorker, 'debt', 999000, 0, 0, 999000, 'unrelated_driver_debt');

    $service = app(CleaningFinancialOverviewService::class);

    expect($service->transactionMetrics())->toBe([
        'currentDebt' => 450000.0,
        'totalDeposits' => 1000000.0,
        'totalSettlements' => 50000.0,
        'totalRefunds' => 100000.0,
    ])->and($service->reportMetrics())->toBe([
        'depositsHeld' => 900000.0,
        'outstandingAdministrationDue' => 450000.0,
        'settlementsReceived' => 50000.0,
        'depositRefunds' => 100000.0,
        'activeWorkers' => 1,
        'restrictedWorkers' => 1,
    ]);
});

function createOverviewWorker(
    UserModuleType $moduleType,
    bool $isActive,
    bool $isSuspended,
    string $depositStatus,
): Worker {
    $user = User::factory()->create([
        'module_type' => $moduleType,
    ]);

    return Worker::factory()->create([
        'user_id' => $user->id,
        'is_active' => $isActive,
        'is_suspended' => $isSuspended,
        'security_deposit_status' => $depositStatus,
    ]);
}

function createOverviewTransaction(
    Worker $worker,
    string $type,
    float $amount,
    float $debtSettledAmount,
    float $balanceBefore,
    float $balanceAfter,
    string $reference,
): CleaningDepositTransaction {
    return CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => $type,
        'amount' => $amount,
        'debt_settled_amount' => $debtSettledAmount,
        'balance_before' => $balanceBefore,
        'balance_after' => $balanceAfter,
        'reference' => $reference,
    ]);
}
