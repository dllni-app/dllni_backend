<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;

it('exposes only the four public transaction types in the worker deposit timeline', function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 50000,
            'default_max_negative_balance' => 0,
            'restriction_threshold_percent' => 80,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );

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
            'deposited_total' => 1000000,
            'withdrawn_total' => 0,
            'minimum_required' => 50000,
            'max_negative_balance' => 0,
            'is_active' => true,
        ],
    );

    $timeline = [
        ['type' => 'deposit', 'amount' => 500000, 'balance_before' => 0, 'balance_after' => 500000, 'reference' => 'test-opening-deposit', 'created_at' => now()->subDays(5)],
        ['type' => 'deposit', 'amount' => 500000, 'balance_before' => 500000, 'balance_after' => 1000000, 'reference' => 'test-second-deposit', 'created_at' => now()->subDays(4)],
        ['type' => 'admin_fee', 'amount' => 45000, 'balance_before' => 1000000, 'balance_after' => 955000, 'reference' => 'automatic_admin_commission:test-1', 'created_at' => now()->subDays(3)],
        ['type' => 'admin_fee', 'amount' => 57500, 'balance_before' => 955000, 'balance_after' => 897500, 'reference' => 'automatic_admin_commission:test-2', 'created_at' => now()->subDays(2)],
        ['type' => 'admin_fee', 'amount' => 70000, 'balance_before' => 897500, 'balance_after' => 827500, 'reference' => 'automatic_admin_commission:test-3', 'created_at' => now()->subDay()],
    ];

    foreach ($timeline as $transactionData) {
        CleaningDepositTransaction::query()->create([
            'worker_id' => $worker->id,
            'type' => $transactionData['type'],
            'amount' => $transactionData['amount'],
            'balance_before' => $transactionData['balance_before'],
            'balance_after' => $transactionData['balance_after'],
            'reference' => $transactionData['reference'],
            'notes' => 'Seeded test transaction',
            'created_at' => $transactionData['created_at'],
            'updated_at' => $transactionData['created_at'],
        ]);
    }

    Sanctum::actingAs($user);

    $statusResponse = $this->getJson('/api/v1/cleaning/worker/account/deposit');
    $statusResponse->assertOk();
    expect((float) $statusResponse->json('currentBalance'))->toBe(827500.0);
    expect((float) $statusResponse->json('depositedTotal'))->toBe(1000000.0);
    expect((float) $statusResponse->json('withdrawnTotal'))->toBe(0.0);
    expect((float) $statusResponse->json('minimumRequired'))->toBe(50000.0);
    expect((float) $statusResponse->json('debtAmount'))->toBe(172500.0);

    $depositTimelineResponse = $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=deposit');
    $depositTimelineResponse->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.filters.appliedType', 'deposit')
        ->assertJsonMissingPath('data.0.cleaningBookingId');

    $debtTimelineResponse = $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=debt');
    $debtTimelineResponse->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.filters.appliedType', 'debt')
        ->assertJsonPath('meta.filters.appliedTypes.0', 'debt')
        ->assertJsonPath('data.0.type', 'debt')
        ->assertJsonPath('data.1.type', 'debt')
        ->assertJsonPath('data.2.type', 'debt')
        ->assertJsonMissingPath('data.0.cleaningBookingId');

    $refundTimelineResponse = $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions?type=withdraw');
    $refundTimelineResponse->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.filters.appliedType', 'refund');
});
