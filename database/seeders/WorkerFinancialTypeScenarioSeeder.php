<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\WorkerDebtService;

final class WorkerFinancialTypeScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $worker = Worker::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'worker2@dllni.sy'))
            ->first();

        if (! $worker instanceof Worker) {
            return;
        }

        DB::transaction(function () use ($worker): void {
            CleaningDepositTransaction::query()
                ->where('worker_id', $worker->id)
                ->where('reference', 'like', '%seed-ahmad-%')
                ->delete();

            CleaningWorkerDeposit::query()->updateOrCreate(
                ['worker_id' => $worker->id],
                [
                    'current_balance' => 600000,
                    'debt_balance' => 0,
                    'deposited_total' => 500000,
                    'withdrawn_total' => 0,
                    'admin_revenue_withdrawn_total' => 0,
                    'minimum_required' => 0,
                    'max_negative_balance' => 100000,
                    'is_active' => true,
                ],
            );

            $now = CarbonImmutable::now();

            $this->createTransaction(
                worker: $worker,
                type: 'debt',
                amount: 100000,
                balanceBefore: 0,
                balanceAfter: 100000,
                reference: WorkerDebtService::ADMIN_LOAN_REFERENCE.':seed-ahmad-opening-loan',
                notes: 'دين إداري مضاف إلى رصيد الإيداع مع بقائه ظاهراً كدين إداري.',
                createdAt: $now->subDays(18),
            );

            $this->createTransaction(
                worker: $worker,
                type: 'deposit',
                amount: 500000,
                balanceBefore: 100000,
                balanceAfter: 600000,
                reference: 'seed-ahmad-new-deposit',
                notes: 'إيداع نقدي أضيف إلى رصيد العامل.',
                createdAt: $now->subDays(10),
            );
        });
    }

    private function createTransaction(
        Worker $worker,
        string $type,
        float $amount,
        float $balanceBefore,
        float $balanceAfter,
        string $reference,
        string $notes,
        CarbonImmutable $createdAt,
    ): void {
        if (! in_array($type, AdminCleaningTransactionService::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported seeded cleaning transaction type [{$type}].");
        }

        $transaction = CleaningDepositTransaction::query()->create([
            'worker_id' => $worker->id,
            'type' => $type,
            'amount' => $amount,
            'debt_settled_amount' => 0,
            'admin_revenue_withdrawn_amount' => 0,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'debt_balance_before' => 0,
            'debt_balance_after' => 0,
            'reference' => $reference,
            'notes' => $notes,
        ]);

        $transaction->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }
}
