<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
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
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('adds an administration loan to deposit without increasing indebtedness', function (): void {
    $user = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $user->id, 'trust_score' => 100, 'security_deposit_status' => 'active']);

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

    app(WorkerDebtService::class)->recordDebt(
        $worker,
        50000,
        WorkerDebtService::ADMIN_LOAN_REFERENCE,
        'Administration-funded deposit.',
    );

    $account = $worker->fresh('deposit')->deposit;
    expect((float) $account->current_balance)->toBe(50000.0)
        ->and((float) $account->debt_balance)->toBe(0.0)
        ->and((float) $account->deposited_total)->toBe(0.0);

    $transaction = CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('type', 'debt')->firstOrFail();
    expect((float) $transaction->balance_before)->toBe(0.0)
        ->and((float) $transaction->balance_after)->toBe(50000.0)
        ->and((float) $transaction->debt_balance_before)->toBe(0.0)
        ->and((float) $transaction->debt_balance_after)->toBe(0.0)
        ->and($transaction->reference)->toBe(WorkerDebtService::ADMIN_LOAN_REFERENCE);

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('depositBalance', 50000)
        ->assertJsonPath('currentBalance', 50000)
        ->assertJsonPath('debtBalance', 0)
        ->assertJsonPath('indebtednessBalance', 0)
        ->assertJsonPath('adminLoanBalance', 50000)
        ->assertJsonPath('hasAdminLoan', true)
        ->assertJsonPath('allowedDebtLimit', 50000)
        ->assertJsonPath('remainingDebtCapacity', 50000);
});

it('blocks an administration loan while the worker has a deposit balance', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100, 'security_deposit_status' => 'active']);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 1000,
        'debt_balance' => 0,
        'deposited_total' => 1000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    app(WorkerDebtService::class)->recordDebt(
        $worker,
        5000,
        WorkerDebtService::ADMIN_LOAN_REFERENCE,
        'Should be rejected.',
    );
})->throws(InvalidArgumentException::class);

it('uses a new worker deposit to settle indebtedness first and stores only the remainder as deposit', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100, 'security_deposit_status' => 'active']);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 40000,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    app(DepositService::class)->recordDeposit($worker, 100000, 'test_deposit', 'Cash received from worker.');

    $account = $worker->fresh('deposit')->deposit;
    expect((float) $account->current_balance)->toBe(60000.0)
        ->and((float) $account->debt_balance)->toBe(0.0)
        ->and((float) $account->deposited_total)->toBe(100000.0);

    $transactions = CleaningDepositTransaction::query()->where('worker_id', $worker->id)->orderBy('id')->get();
    expect($transactions)->toHaveCount(2)
        ->and($transactions[0]->type)->toBe('settlement')
        ->and((float) $transactions[0]->amount)->toBe(40000.0)
        ->and((float) $transactions[0]->debt_balance_after)->toBe(0.0)
        ->and($transactions[1]->type)->toBe('deposit')
        ->and((float) $transactions[1]->amount)->toBe(60000.0)
        ->and((float) $transactions[1]->balance_after)->toBe(60000.0);
});

it('settles indebtedness without increasing the deposit balance', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 25000,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    $transaction = app(WorkerDebtService::class)->recordSettlement($worker, 25000, 'test_full_settlement');
    $account = $worker->fresh('deposit')->deposit;
    expect((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->debt_balance)->toBe(0.0)
        ->and((float) $transaction->balance_before)->toBe(0.0)
        ->and((float) $transaction->balance_after)->toBe(0.0)
        ->and((float) $transaction->debt_balance_before)->toBe(25000.0)
        ->and((float) $transaction->debt_balance_after)->toBe(0.0);
});
