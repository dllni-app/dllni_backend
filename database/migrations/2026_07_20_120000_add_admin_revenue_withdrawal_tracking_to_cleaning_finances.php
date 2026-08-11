<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_worker_deposits', 'admin_revenue_withdrawn_total')) {
                $table->decimal('admin_revenue_withdrawn_total', 12, 2)->default(0)->after('withdrawn_total');
            }
        });

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_deposit_transactions', 'admin_revenue_withdrawn_amount')) {
                $table->decimal('admin_revenue_withdrawn_amount', 12, 2)->default(0)->after('debt_settled_amount');
            }
        });

        $this->backfillPreviousFullAccountClosures();
    }

    public function down(): void
    {
        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_deposit_transactions', 'admin_revenue_withdrawn_amount')) {
                $table->dropColumn('admin_revenue_withdrawn_amount');
            }
        });

        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_worker_deposits', 'admin_revenue_withdrawn_total')) {
                $table->dropColumn('admin_revenue_withdrawn_total');
            }
        });
    }

    private function backfillPreviousFullAccountClosures(): void
    {
        DB::table('cleaning_worker_deposits')
            ->select(['id', 'worker_id'])
            ->orderBy('id')
            ->eachById(function (object $account): void {
                $pendingCommission = 0.0;
                $withdrawnAdminRevenue = 0.0;

                $transactions = DB::table('cleaning_deposit_transactions')
                    ->where('worker_id', $account->worker_id)
                    ->orderBy('id')
                    ->get(['id', 'type', 'amount', 'reference']);

                foreach ($transactions as $transaction) {
                    $type = (string) $transaction->type;
                    $amount = (float) $transaction->amount;
                    $reference = (string) ($transaction->reference ?? '');

                    $isCommission = in_array($type, ['commission', 'admin_fee'], true)
                        || ($type === 'debt' && str_starts_with($reference, 'automatic_admin_commission:'));

                    if ($isCommission) {
                        $pendingCommission += abs($amount);

                        continue;
                    }

                    $isAccountClosure = in_array($type, ['refund', 'withdrawal'], true)
                        || ($type === 'adjustment' && $amount < 0);

                    if (! $isAccountClosure) {
                        continue;
                    }

                    DB::table('cleaning_deposit_transactions')
                        ->where('id', $transaction->id)
                        ->update(['admin_revenue_withdrawn_amount' => $pendingCommission]);

                    $withdrawnAdminRevenue += $pendingCommission;
                    $pendingCommission = 0.0;
                }

                DB::table('cleaning_worker_deposits')
                    ->where('id', $account->id)
                    ->update(['admin_revenue_withdrawn_total' => $withdrawnAdminRevenue]);
            });
    }
};
