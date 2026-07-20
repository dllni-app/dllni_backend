<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Services\CleaningFinancialSummaryService;

it('returns only the canonical current financial balances', function (): void {
    $firstWorker = createWorkerForFinancialSummary('active');
    $secondWorker = createWorkerForFinancialSummary('insufficient_balance');

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $firstWorker->id,
        'current_balance' => 1000,
        'debt_balance' => 0,
        'deposited_total' => 1200,
        'withdrawn_total' => 200,
        'admin_revenue_withdrawn_total' => 100,
        'minimum_required' => 0,
        'max_negative_balance' => 500,
        'is_active' => true,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $secondWorker->id,
        'current_balance' => 0,
        'debt_balance' => 200,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 50,
        'minimum_required' => 0,
        'max_negative_balance' => 500,
        'is_active' => true,
    ]);

    CleaningDepositTransaction::query()->create([
        'worker_id' => $firstWorker->id,
        'type' => 'commission',
        'amount' => 300,
        'balance_before' => 1300,
        'balance_after' => 1000,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => 'summary-test-first-commission',
    ]);

    CleaningDepositTransaction::query()->create([
        'worker_id' => $secondWorker->id,
        'type' => 'commission',
        'amount' => 500,
        'balance_before' => 300,
        'balance_after' => 0,
        'debt_balance_before' => 0,
        'debt_balance_after' => 200,
        'reference' => 'summary-test-second-commission',
    ]);

    $summary = app(CleaningFinancialSummaryService::class)->global();

    expect($summary['currentDepositBalance'])->toBe(1000.0)
        ->and($summary['currentDebtBalance'])->toBe(200.0)
        ->and($summary['currentAdminCommissionBalance'])->toBe(650.0)
        ->and($summary['withdrawnAdminRevenue'])->toBe(150.0)
        ->and($summary['financiallyBlockedWorkers'])->toBe(1)
        ->and($summary['reservedActiveCommission'])->toBe(0.0);
});

function createWorkerForFinancialSummary(string $financialStatus): Worker
{
    $user = User::factory()->create(['module_type' => UserModuleType::CleaningWorker]);

    return Worker::factory()->create([
        'user_id' => $user->id,
        'security_deposit_status' => $financialStatus,
        'is_active' => true,
        'is_suspended' => false,
    ]);
}
