<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
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

it('uses worker deposits to repay the administration loan before increasing usable balance', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100, 'security_deposit_status' => 'active']);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
        'is_active' => true,
    ]);

    app(WorkerDebtService::class)->recordDebt(
        $worker,
        999998,
        WorkerDebtService::ADMIN_LOAN_REFERENCE,
        'Administration-funded balance.',
    );

    $fundedWorker = $worker->fresh(['deposit']);
    expect(app(AdminCleaningTransactionService::class)->projectedBalance($fundedWorker, 'deposit', 800000))
        ->toBe(999998.0);

    $firstDeposit = app(DepositService::class)->recordDeposit(
        $fundedWorker,
        800000,
        'worker_cash_deposit',
        'Partial administration-loan repayment.',
    );

    $afterFirstDeposit = $worker->fresh(['deposit']);
    $firstSummary = app(WorkerDebtService::class)->summary($afterFirstDeposit);

    expect((float) $afterFirstDeposit->deposit->current_balance)->toBe(999998.0)
        ->and((float) $afterFirstDeposit->deposit->deposited_total)->toBe(800000.0)
        ->and((float) $firstSummary['adminLoanBalance'])->toBe(199998.0)
        ->and((float) $firstSummary['manualDebtSettled'])->toBe(800000.0)
        ->and((float) $firstDeposit->amount)->toBe(800000.0)
        ->and((float) $firstDeposit->debt_settled_amount)->toBe(800000.0)
        ->and((float) $firstDeposit->balance_before)->toBe(999998.0)
        ->and((float) $firstDeposit->balance_after)->toBe(999998.0)
        ->and($firstDeposit->reference)->toStartWith(CleaningDepositTransaction::ADMIN_LOAN_DEPOSIT_REPAYMENT_REFERENCE_PREFIX);

    $secondDeposit = app(DepositService::class)->recordDeposit(
        $afterFirstDeposit,
        300000,
        'worker_cash_deposit_2',
        'Complete administration-loan repayment and add the remainder.',
    );

    $afterSecondDeposit = $worker->fresh(['deposit']);
    $secondSummary = app(WorkerDebtService::class)->summary($afterSecondDeposit);

    expect((float) $afterSecondDeposit->deposit->current_balance)->toBe(1100000.0)
        ->and((float) $afterSecondDeposit->deposit->deposited_total)->toBe(1100000.0)
        ->and((float) $secondSummary['adminLoanBalance'])->toBe(0.0)
        ->and((float) $secondSummary['manualDebtSettled'])->toBe(999998.0)
        ->and((float) $secondDeposit->debt_settled_amount)->toBe(199998.0)
        ->and((float) $secondDeposit->balance_before)->toBe(999998.0)
        ->and((float) $secondDeposit->balance_after)->toBe(1100000.0);
});

it('reactivates the financial account and redispatches pending preferred bookings after an administration loan', function (): void {
    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'insufficient_balance',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 800000,
        'withdrawn_total' => 800000,
        'minimum_required' => 0,
        'max_negative_balance' => 999998,
        'is_active' => false,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => 'preferred_worker',
        'status' => CleaningBookingStatus::Pending->value,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'base_price' => 60000,
        'addons_total' => 0,
        'admin_margin_amount' => 6000,
        'total_price' => 66000,
    ]);

    Queue::fake();

    app(WorkerDebtService::class)->recordDebt(
        $worker,
        999998,
        WorkerDebtService::ADMIN_LOAN_REFERENCE,
        'Restore worker capacity after a full refund.',
    );

    $fundedWorker = $worker->fresh(['deposit']);

    expect((float) $fundedWorker->deposit->current_balance)->toBe(999998.0)
        ->and((float) $fundedWorker->deposit->debt_balance)->toBe(0.0)
        ->and($fundedWorker->deposit->is_active)->toBeTrue()
        ->and($fundedWorker->security_deposit_status)->toBe('active')
        ->and(app(DepositService::class)->availableCommissionCapacity($fundedWorker))->toBe(999998.0);

    Queue::assertPushed(NotifyEligibleWorkersNewOrderJob::class, 1);
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
