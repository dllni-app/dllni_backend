<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Services\WorkerDebtService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'restriction_threshold_percent' => 80,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('adds administration debt as usable worker balance without counting it as a deposit', function (): void {
    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
        'is_active' => true,
    ]);

    app(WorkerDebtService::class)->recordDebt(
        $worker,
        100000,
        'test_admin_debt',
        'Debt used to enable order reception.',
    );

    $deposit = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail();

    expect((float) $deposit->current_balance)->toBe(100000.0)
        ->and((float) $deposit->deposited_total)->toBe(0.0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('type', 'debt')->count())->toBe(1);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('currentBalance', 100000)
        ->assertJsonPath('debtAmount', 100000)
        ->assertJsonPath('manualDebtAmount', 100000)
        ->assertJsonPath('adminCommissionDebtAmount', 0);

    $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=debt')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.appliedType', 'debt')
        ->assertJsonPath('data.0.type', 'debt');
});

it('settles administration-funded debt before automatic administration debt and keeps the balance chain correct', function (): void {
    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
        'is_active' => true,
    ]);

    $service = app(WorkerDebtService::class);
    $service->recordDebt($worker, 100000, 'test_admin_debt');

    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'debt',
        'amount' => 20000,
        'debt_settled_amount' => 0,
        'balance_before' => 100000,
        'balance_after' => 80000,
        'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'test',
    ]);

    CleaningWorkerDeposit::query()
        ->where('worker_id', $worker->id)
        ->update(['current_balance' => 80000]);

    $settlement = $service->recordSettlement($worker, 120000, 'test_settlement');
    $summary = $service->summary($worker);
    $deposit = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail();

    expect((float) $settlement->debt_settled_amount)->toBe(100000.0)
        ->and((float) $settlement->balance_before)->toBe(80000.0)
        ->and((float) $settlement->balance_after)->toBe(0.0)
        ->and((float) $deposit->current_balance)->toBe(0.0)
        ->and($summary['manualDebtDue'])->toBe(0.0)
        ->and($summary['adminFeeDue'])->toBe(0.0)
        ->and($summary['outstandingAdministrationDue'])->toBe(0.0);
});
