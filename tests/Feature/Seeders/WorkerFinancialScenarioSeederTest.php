<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use Database\Seeders\WorkerSeeder;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Services\AdminCleaningTransactionService;

it('seeds continuous financial scenarios with separate deposit and debt balances', function (): void {
    $this->seed(WorkerSeeder::class);

    $types = CleaningDepositTransaction::query()
        ->where('reference', 'like', 'seed-%')
        ->distinct()
        ->orderBy('type')
        ->pluck('type')
        ->all();

    expect($types)->toBe(['commission', 'deposit', 'refund', 'settlement'])
        ->and(Schema::hasColumn('cleaning_deposit_transactions', 'cleaning_booking_id'))->toBeFalse()
        ->and(Schema::hasColumn('cleaning_deposit_transactions', 'debt_balance_before'))->toBeTrue()
        ->and(Schema::hasColumn('cleaning_deposit_transactions', 'debt_balance_after'))->toBeTrue()
        ->and(Schema::hasColumn('cleaning_deposit_transactions', 'admin_revenue_withdrawn_amount'))->toBeTrue();

    $workers = Worker::query()
        ->whereHas('user', fn ($query) => $query->whereIn('email', [
            'worker1@dllni.sy',
            'worker2@dllni.sy',
            'worker3@dllni.sy',
        ]))
        ->with('deposit')
        ->get();

    expect($workers)->toHaveCount(3);

    foreach ($workers as $worker) {
        $transactions = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('reference', 'like', 'seed-%')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        expect($transactions)->not->toBeEmpty()
            ->and((float) $transactions->first()->balance_before)->toBe(0.0)
            ->and((float) $transactions->first()->debt_balance_before)->toBe(0.0)
            ->and((float) $transactions->last()->balance_after)->toBe((float) $worker->deposit?->current_balance)
            ->and((float) $transactions->last()->debt_balance_after)->toBe((float) $worker->deposit?->debt_balance)
            ->and(min((float) $worker->deposit?->current_balance, (float) $worker->deposit?->debt_balance))->toBe(0.0)
            ->and((float) $worker->deposit?->admin_revenue_withdrawn_total)
            ->toBe((float) $transactions->sum('admin_revenue_withdrawn_amount'));

        for ($index = 1; $index < $transactions->count(); $index++) {
            expect((float) $transactions[$index - 1]->balance_after)
                ->toBe((float) $transactions[$index]->balance_before)
                ->and((float) $transactions[$index - 1]->debt_balance_after)
                ->toBe((float) $transactions[$index]->debt_balance_before);
        }

        foreach ($transactions as $transaction) {
            expect($transaction->publicType())->toBeIn(CleaningDepositTransaction::PUBLIC_TYPES)
                ->and($transaction->notes)->not->toBeEmpty();

            if ($transaction->publicType() === 'refund') {
                expect((float) $transaction->amount)->toBe((float) $transaction->balance_before)
                    ->and((float) $transaction->balance_after)->toBe(0.0)
                    ->and((float) $transaction->debt_balance_after)->toBe(0.0);
            }
        }
    }
});

it('seeds current commission and withdrawn administration revenue as separate values', function (): void {
    $this->seed(WorkerSeeder::class);

    $worker = Worker::query()
        ->whereHas('user', fn ($query) => $query->where('email', 'worker1@dllni.sy'))
        ->with('deposit')
        ->firstOrFail();

    $snapshot = app(AdminCleaningTransactionService::class)->snapshot($worker);

    expect($snapshot['depositBalance'])->toBe(725000.0)
        ->and($snapshot['debtBalance'])->toBe(0.0)
        ->and($snapshot['adminCommissionBalance'])->toBe(75000.0)
        ->and($snapshot['withdrawnAdminRevenueTotal'])->toBe(100000.0);
});

it('keeps the financial scenarios idempotent when reseeded', function (): void {
    $this->seed(WorkerSeeder::class);
    $initialCount = CleaningDepositTransaction::query()
        ->where('reference', 'like', 'seed-%')
        ->count();

    $this->seed(WorkerSeeder::class);

    expect(CleaningDepositTransaction::query()->where('reference', 'like', 'seed-%')->count())
        ->toBe($initialCount);
});
