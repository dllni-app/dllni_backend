<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('returns inactive financial eligibility after the full deposit balance is refunded', function (): void {
    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 1000,
        'withdrawn_total' => 1000,
        'minimum_required' => 0,
        'max_negative_balance' => 500,
        'is_active' => true,
    ]);

    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'refund',
        'amount' => 1000,
        'balance_before' => 1000,
        'balance_after' => 0,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => 'admin_full_account_refund',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('status', 'inactive')
        ->assertJsonPath('isFinancialAccountActive', false)
        ->assertJsonPath('isActive', false)
        ->assertJsonPath('isEligibleForNewRequests', false);

    $this->getJson('/api/v1/cleaning/worker/homepage')
        ->assertOk()
        ->assertJsonPath('isEligibleForNewRequests', false)
        ->assertJsonPath('newOrdersCount', 0)
        ->assertJsonPath('depositSummary.status', 'inactive')
        ->assertJsonPath('depositSummary.isEligibleForNewRequests', false)
        ->assertJsonPath('depositSummary.isFinancialAccountActive', false)
        ->assertJsonPath('dispatchEligibility.reasonCode', 'financial_account_inactive')
        ->assertJsonPath('dispatchEligibility.canReceiveNewRequests', false)
        ->assertJsonPath('commissionCapacityEligibility.reasonCode', 'financial_account_inactive')
        ->assertJsonPath('commissionCapacityEligibility.canReceiveNewRequests', false);
});

it('reactivates financial eligibility after a later funding transaction', function (): void {
    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
    ]);

    $account = CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 1000,
        'withdrawn_total' => 1000,
        'minimum_required' => 0,
        'max_negative_balance' => 500,
        'is_active' => true,
    ]);

    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'refund',
        'amount' => 1000,
        'balance_before' => 1000,
        'balance_after' => 0,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => 'admin_full_account_refund',
    ]);

    $account->update(['current_balance' => 500]);
    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'deposit',
        'amount' => 500,
        'balance_before' => 0,
        'balance_after' => 500,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => 'worker_cash_deposit',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('isFinancialAccountActive', true)
        ->assertJsonPath('isActive', true)
        ->assertJsonPath('isEligibleForNewRequests', true);
});
