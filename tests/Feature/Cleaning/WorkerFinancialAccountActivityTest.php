<?php

declare(strict_types=1);

use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerFinancialAccountStatusService;

function createFinancialAccount(Worker $worker, float $debt, ?float $limit): CleaningWorkerDeposit
{
    return CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => $debt,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => $limit,
        'is_active' => true,
    ]);
}

it('keeps a worker financially active while debt is within the individual limit', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100]);
    createFinancialAccount($worker, debt: 100, limit: 100);

    $freshWorker = $worker->fresh(['deposit']);

    expect(app(WorkerFinancialAccountStatusService::class)->status($freshWorker))
        ->toBe(WorkerFinancialAccountStatusService::ACTIVE)
        ->and(app(DepositService::class)->isWorkerEligibleForNewRequests($freshWorker))->toBeTrue();
});

it('makes a worker financially inactive only after debt exceeds the individual limit', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100, 'security_deposit_status' => 'active']);
    createFinancialAccount($worker, debt: 101, limit: 100);

    $service = app(DepositService::class);
    $freshWorker = $worker->fresh(['deposit']);
    $service->syncEligibilityStatus($freshWorker);

    expect(app(WorkerFinancialAccountStatusService::class)->status($freshWorker))
        ->toBe(WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE)
        ->and($service->isWorkerEligibleForNewRequests($freshWorker))->toBeFalse()
        ->and($worker->fresh()->security_deposit_status)->toBe('insufficient_balance');
});

it('uses each worker debt limit independently', function (): void {
    $lowLimitWorker = Worker::factory()->create(['trust_score' => 100]);
    $highLimitWorker = Worker::factory()->create(['trust_score' => 100]);

    createFinancialAccount($lowLimitWorker, debt: 150, limit: 100);
    createFinancialAccount($highLimitWorker, debt: 150, limit: 200);

    $statusService = app(WorkerFinancialAccountStatusService::class);
    $depositService = app(DepositService::class);

    expect($statusService->status($lowLimitWorker->fresh(['deposit'])))
        ->toBe(WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE)
        ->and($statusService->status($highLimitWorker->fresh(['deposit'])))
        ->toBe(WorkerFinancialAccountStatusService::ACTIVE)
        ->and($depositService->isWorkerEligibleForNewRequests($lowLimitWorker->fresh(['deposit'])))->toBeFalse()
        ->and($depositService->isWorkerEligibleForNewRequests($highLimitWorker->fresh(['deposit'])))->toBeTrue();
});

it('does not fall back when the worker debt limit is missing', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100]);
    createFinancialAccount($worker, debt: 1, limit: null);

    $freshWorker = $worker->fresh(['deposit']);

    expect(app(DepositService::class)->resolveLimits($freshWorker)['maxNegativeBalance'])->toBe(0.0)
        ->and(app(WorkerFinancialAccountStatusService::class)->status($freshWorker))
        ->toBe(WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE);
});

it('removes the global finance toggle and default debt limit columns', function (): void {
    expect(Schema::hasColumn('cleaning_deposit_settings', 'is_enabled'))->toBeFalse()
        ->and(Schema::hasColumn('cleaning_deposit_settings', 'default_max_negative_balance'))->toBeFalse();
});
