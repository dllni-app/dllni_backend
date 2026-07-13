<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use Database\Seeders\WorkerSeeder;
use Illuminate\Support\Facades\Schema;

it('seeds realistic continuous financial scenarios using only four transaction types', function (): void {
    $this->seed(WorkerSeeder::class);

    $types = CleaningDepositTransaction::query()
        ->where('reference', 'like', 'seed-%')
        ->distinct()
        ->orderBy('type')
        ->pluck('type')
        ->all();

    expect($types)->toBe(['debt', 'deposit', 'refund', 'settlement'])
        ->and(Schema::hasColumn('cleaning_deposit_transactions', 'cleaning_booking_id'))->toBeFalse();

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
            ->and((float) $transactions->last()->balance_after)->toBe((float) $worker->deposit?->current_balance);

        for ($index = 1; $index < $transactions->count(); $index++) {
            expect((float) $transactions[$index - 1]->balance_after)
                ->toBe((float) $transactions[$index]->balance_before);
        }

        foreach ($transactions as $transaction) {
            expect($transaction->type)->toBeIn(CleaningDepositTransaction::PUBLIC_TYPES)
                ->and($transaction->notes)->not->toBeEmpty();
        }
    }
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
