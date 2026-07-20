<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('cleaning_worker_deposits')
            || ! Schema::hasTable('cleaning_deposit_transactions')
        ) {
            return;
        }

        DB::table('cleaning_worker_deposits')
            ->select(['id', 'worker_id'])
            ->orderBy('id')
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    $totals = DB::table('cleaning_deposit_transactions')
                        ->where('worker_id', $account->worker_id)
                        ->selectRaw('COUNT(*) AS transaction_count')
                        ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('deposit', 'settlement') THEN ABS(amount) WHEN type = 'adjustment' AND amount > 0 THEN amount ELSE 0 END), 0) AS deposited_total")
                        ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('refund', 'withdrawal') THEN ABS(amount) WHEN type = 'adjustment' AND amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS withdrawn_total")
                        ->first();

                    if ((int) ($totals?->transaction_count ?? 0) === 0) {
                        continue;
                    }

                    DB::table('cleaning_worker_deposits')
                        ->where('id', $account->id)
                        ->update([
                            'deposited_total' => round(max(0.0, (float) ($totals?->deposited_total ?? 0)), 2),
                            'withdrawn_total' => round(max(0.0, (float) ($totals?->withdrawn_total ?? 0)), 2),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Ledger-derived cumulative totals cannot be restored safely to stale legacy values.
    }
};
