<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningWorkerDeposits\Tables\CleaningTransactionsTable;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;

function seedTimelineDepositSettings(): CleaningDepositSetting
{
    return CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
}

it('exposes the separate public transaction types in the worker financial timeline', function (): void {
    seedTimelineDepositSettings();

    $user = User::factory()->create([
        'phone' => '+963944100001',
        'password' => bcrypt('password'),
    ]);

    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 90,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 827500,
            'debt_balance' => 0,
            'deposited_total' => 1000000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 50000,
            'is_active' => true,
        ],
    );

    $timeline = [
        ['type' => 'deposit', 'amount' => 500000, 'balance_before' => 0, 'balance_after' => 500000, 'reference' => 'test-opening-deposit', 'created_at' => now()->subDays(5)],
        ['type' => 'deposit', 'amount' => 500000, 'balance_before' => 500000, 'balance_after' => 1000000, 'reference' => 'test-second-deposit', 'created_at' => now()->subDays(4)],
        ['type' => 'commission', 'amount' => 45000, 'balance_before' => 1000000, 'balance_after' => 955000, 'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'test-1', 'created_at' => now()->subDays(3)],
        ['type' => 'commission', 'amount' => 57500, 'balance_before' => 955000, 'balance_after' => 897500, 'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'test-2', 'created_at' => now()->subDays(2)],
        ['type' => 'commission', 'amount' => 70000, 'balance_before' => 897500, 'balance_after' => 827500, 'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'test-3', 'created_at' => now()->subDay()],
    ];

    foreach ($timeline as $transactionData) {
        CleaningDepositTransaction::query()->create([
            'worker_id' => $worker->id,
            'type' => $transactionData['type'],
            'amount' => $transactionData['amount'],
            'balance_before' => $transactionData['balance_before'],
            'balance_after' => $transactionData['balance_after'],
            'debt_balance_before' => 0,
            'debt_balance_after' => 0,
            'reference' => $transactionData['reference'],
            'notes' => 'Seeded test transaction',
            'created_at' => $transactionData['created_at'],
            'updated_at' => $transactionData['created_at'],
        ]);
    }

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('depositBalance', 827500)
        ->assertJsonPath('currentBalance', 827500)
        ->assertJsonPath('debtBalance', 0)
        ->assertJsonPath('depositedTotal', 1000000)
        ->assertJsonPath('withdrawnTotal', 0)
        ->assertJsonPath('minimumRequired', 0)
        ->assertJsonPath('allowedDebtLimit', 50000)
        ->assertJsonPath('status', 'active')
        ->assertJsonMissingPath('isActive')
        ->assertJsonMissingPath('isFinancialAccountActive');

    $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=deposit')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.filters.appliedType', 'deposit')
        ->assertJsonMissingPath('data.0.cleaningBookingId');

    $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=commission')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.filters.appliedType', 'commission')
        ->assertJsonPath('data.0.type', 'commission')
        ->assertJsonPath('data.1.type', 'commission')
        ->assertJsonPath('data.2.type', 'commission')
        ->assertJsonMissingPath('data.0.cleaningBookingId');

    $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=debt')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.filters.appliedType', 'debt');

    $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=withdraw')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.filters.appliedType', 'refund');
});

it('returns active status while debt remains within the worker-specific limit even without a deposit', function (): void {
    seedTimelineDepositSettings();

    $user = User::factory()->create(['phone' => '+963944100003']);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 90,
        'security_deposit_status' => 'insufficient_balance',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 100,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100,
        'is_active' => false,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('isEligibleForNewRequests', true)
        ->assertJsonMissingPath('isFinancialAccountActive');
});

it('returns inactive financial status when debt exceeds the worker-specific limit', function (): void {
    seedTimelineDepositSettings();

    $user = User::factory()->create(['phone' => '+963944100005']);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 90,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 500,
        'debt_balance' => 101,
        'deposited_total' => 500,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100,
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('status', 'insufficient_balance')
        ->assertJsonPath('isEligibleForNewRequests', false);
});

it('uses the debt label for settlement rows in the Filament transaction table', function (): void {
    expect(CleaningTransactionsTable::typeLabel('settlement'))
        ->toBe(CleaningTransactionsTable::typeLabel('debt'))
        ->and(CleaningTransactionsTable::typeColor('settlement'))
        ->toBe(CleaningTransactionsTable::typeColor('debt'));
});

it('keeps deposit API cumulative totals equal to the financial ledger', function (): void {
    seedTimelineDepositSettings();

    $user = User::factory()->create([
        'phone' => '+963944100002',
        'password' => bcrypt('password'),
    ]);

    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 90,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 250000,
        'debt_balance' => 0,
        'deposited_total' => 3000000,
        'withdrawn_total' => 2000000,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);

    $timeline = [
        [
            'type' => 'settlement',
            'amount' => 1000000,
            'balance_before' => 0,
            'balance_after' => 0,
            'debt_balance_before' => 1000000,
            'debt_balance_after' => 0,
            'reference' => 'cash-deposit:debt-settlement',
        ],
        [
            'type' => 'deposit',
            'amount' => 500000,
            'balance_before' => 0,
            'balance_after' => 500000,
            'debt_balance_before' => 0,
            'debt_balance_after' => 0,
            'reference' => 'cash-deposit:deposit-remainder',
        ],
        [
            'type' => 'refund',
            'amount' => 250000,
            'balance_before' => 500000,
            'balance_after' => 250000,
            'debt_balance_before' => 0,
            'debt_balance_after' => 0,
            'reference' => 'partial-refund',
        ],
    ];

    foreach ($timeline as $transactionData) {
        CleaningDepositTransaction::query()->create([
            'worker_id' => $worker->id,
            ...$transactionData,
        ]);
    }

    $account = $worker->fresh('deposit')->deposit;
    expect((float) $account->deposited_total)->toBe(1500000.0)
        ->and((float) $account->withdrawn_total)->toBe(250000.0);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('currentBalance', 250000)
        ->assertJsonPath('depositedTotal', 1500000)
        ->assertJsonPath('withdrawnTotal', 250000)
        ->assertJsonPath('status', 'active');
});
