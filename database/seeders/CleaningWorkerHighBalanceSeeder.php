<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Worker;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Services\DepositService;

final class CleaningWorkerHighBalanceSeeder extends Seeder
{
    private const int DefaultDepositBalance = 1_000_000;

    public function run(): void
    {
        $depositService = app(DepositService::class);

        Worker::query()
            ->whereHas('user', fn ($query) => $query
                ->whereIn('email', [
                    'cleaning.worker@dllni.sy',
                    'cleaning.worker2@dllni.sy',
                    'cleaning.worker3@dllni.sy',
                    'worker1@dllni.sy',
                    'worker2@dllni.sy',
                    'worker3@dllni.sy',
                ]))
            ->with('deposit')
            ->each(function (Worker $worker) use ($depositService): void {
                $depositBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
                $debtBalance = max(0.0, (float) ($worker->deposit?->debt_balance ?? 0));
                $topUpAmount = $debtBalance + max(0.0, self::DefaultDepositBalance - $depositBalance);

                if ($topUpAmount <= 0) {
                    return;
                }

                $depositService->recordDeposit(
                    worker: $worker,
                    amount: $topUpAmount,
                    reference: 'seed-high-balance-top-up',
                    notes: 'تسوية المديونية ورفع رصيد الإيداع للحساب التجريبي دون تعديل السجل المالي مباشرة.',
                );
            });
    }
}
