<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Database\Seeder;

final class CleaningWorkerHighBalanceSeeder extends Seeder
{
    private const int DefaultDepositBalance = 1_000_000;

    public function run(): void
    {
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
            ->each(function (Worker $worker): void {
                CleaningWorkerDeposit::updateOrCreate(
                    ['worker_id' => $worker->id],
                    [
                        'current_balance' => self::DefaultDepositBalance,
                        'deposited_total' => self::DefaultDepositBalance,
                        'withdrawn_total' => 0,
                        'is_active' => true,
                    ]
                );

                $worker->forceFill(['security_deposit_status' => 'active'])->save();
            });
    }
}
