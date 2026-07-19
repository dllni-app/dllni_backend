<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileCleaningWorkerFinancialBalances extends Command
{
    protected $signature = 'cleaning:reconcile-worker-finances
        {--apply : Persist the calculated balances. Without this flag the command is a dry run}
        {--worker= : Reconcile one worker ID only}';

    protected $description = 'Rebuild separate cleaning-worker deposit and debt balances from the financial transaction ledger.';

    public function handle(): int
    {
        $query = CleaningWorkerDeposit::query()->orderBy('id');
        $workerId = $this->option('worker');
        if (is_numeric($workerId) && (int) $workerId > 0) {
            $query->where('worker_id', (int) $workerId);
        }

        $apply = (bool) $this->option('apply');
        $changed = 0;
        $rows = [];

        $query->chunkById(100, function ($accounts) use ($apply, &$changed, &$rows): void {
            foreach ($accounts as $account) {
                if (! $account instanceof CleaningWorkerDeposit) {
                    continue;
                }

                $proposal = $this->proposalFor($account);
                $isChanged = abs((float) $account->current_balance - $proposal['depositBalance']) > 0.009
                    || abs((float) $account->debt_balance - $proposal['debtBalance']) > 0.009
                    || abs((float) $account->deposited_total - $proposal['depositedTotal']) > 0.009
                    || abs((float) $account->withdrawn_total - $proposal['withdrawnTotal']) > 0.009;

                if ($isChanged) {
                    $changed++;
                }

                $rows[] = [
                    $account->worker_id,
                    number_format((float) $account->current_balance, 2),
                    number_format((float) $account->debt_balance, 2),
                    number_format($proposal['depositBalance'], 2),
                    number_format($proposal['debtBalance'], 2),
                    $proposal['source'],
                    $isChanged ? 'yes' : 'no',
                ];

                if ($apply && $isChanged) {
                    DB::transaction(function () use ($account, $proposal): void {
                        CleaningWorkerDeposit::query()
                            ->whereKey($account->id)
                            ->lockForUpdate()
                            ->update([
                                'current_balance' => $proposal['depositBalance'],
                                'debt_balance' => $proposal['debtBalance'],
                                'deposited_total' => $proposal['depositedTotal'],
                                'withdrawn_total' => $proposal['withdrawnTotal'],
                                'minimum_required' => 0,
                            ]);
                    });
                }
            }
        });

        $this->table(['Worker', 'Old deposit', 'Old debt', 'New deposit', 'New debt', 'Source', 'Changed'], $rows);

        $mode = $apply ? 'applied' : 'dry-run';
        $this->info("Reconciliation {$mode} complete. {$changed} account(s) require changes.");

        if (! $apply && $changed > 0) {
            $this->warn('Review the report and run the command again with --apply after taking a database backup.');
        }

        return self::SUCCESS;
    }

    private function proposalFor(CleaningWorkerDeposit $account): array
    {
        $transactions = CleaningDepositTransaction::query()
            ->where('worker_id', $account->worker_id)
            ->selectRaw("COUNT(*) as transaction_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' THEN ABS(amount) WHEN type = 'adjustment' AND amount > 0 THEN amount ELSE 0 END), 0) as deposits")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('refund', 'withdrawal') THEN ABS(amount) WHEN type = 'adjustment' AND amount < 0 THEN ABS(amount) ELSE 0 END), 0) as refunds")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('commission', 'admin_fee', 'debt') THEN ABS(amount) ELSE 0 END), 0) as charges")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN ABS(amount) ELSE 0 END), 0) as settlements")
            ->first();

        if ((int) ($transactions?->transaction_count ?? 0) === 0) {
            $legacyBalance = (float) $account->current_balance;
            $legacyDebt = max(0.0, (float) ($account->debt_balance ?? 0), -$legacyBalance);
            $legacyDeposit = max(0.0, $legacyBalance);
            $offset = min($legacyDeposit, $legacyDebt);

            return [
                'depositBalance' => round($legacyDeposit - $offset, 2),
                'debtBalance' => round($legacyDebt - $offset, 2),
                'depositedTotal' => round(max((float) $account->deposited_total, $legacyDeposit), 2),
                'withdrawnTotal' => round(max(0.0, (float) $account->withdrawn_total), 2),
                'source' => 'legacy-balance',
            ];
        }

        $deposits = max(0.0, (float) ($transactions?->deposits ?? 0));
        $refunds = max(0.0, (float) ($transactions?->refunds ?? 0));
        $charges = max(0.0, (float) ($transactions?->charges ?? 0));
        $settlements = max(0.0, (float) ($transactions?->settlements ?? 0));
        $availableDeposit = max(0.0, $deposits - $refunds);
        $outstandingCharges = max(0.0, $charges - $settlements);
        $offset = min($availableDeposit, $outstandingCharges);

        return [
            'depositBalance' => round($availableDeposit - $offset, 2),
            'debtBalance' => round($outstandingCharges - $offset, 2),
            'depositedTotal' => round(max((float) $account->deposited_total, $deposits), 2),
            'withdrawnTotal' => round(max((float) $account->withdrawn_total, $refunds), 2),
            'source' => 'transaction-ledger',
        ];
    }
}
