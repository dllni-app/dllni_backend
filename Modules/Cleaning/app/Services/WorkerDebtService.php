<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;

final class WorkerDebtService
{
    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    public function recordDebt(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null,
    ): CleaningDepositTransaction {
        return $this->depositService->recordDebtCharge(
            worker: $worker,
            amount: $amount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
        );
    }

    public function recordSettlement(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null,
    ): CleaningDepositTransaction {
        return $this->depositService->recordSettlement(
            worker: $worker,
            amount: $amount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
        );
    }

    public function summary(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $automaticPrefix = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%';

        $totals = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debt' AND (reference IS NULL OR reference NOT LIKE ?) THEN amount ELSE 0 END), 0) as manual_debt_total", [$automaticPrefix])
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('commission', 'admin_fee') OR (type = 'debt' AND reference LIKE ?) THEN amount ELSE 0 END), 0) as admin_fee_total", [$automaticPrefix])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN amount ELSE 0 END), 0) as settlement_total")
            ->first();

        $manualDebtTotal = max(0.0, (float) ($totals?->manual_debt_total ?? 0));
        $adminFeeTotal = max(0.0, (float) ($totals?->admin_fee_total ?? 0));
        $totalSettled = max(0.0, (float) ($totals?->settlement_total ?? 0));
        $actualOutstanding = max(0.0, (float) ($worker->deposit?->debt_balance ?? 0));

        $manualDebtSettled = min($manualDebtTotal, $totalSettled);
        $remainingSettlement = max(0.0, $totalSettled - $manualDebtSettled);
        $adminFeeSettled = min($adminFeeTotal, $remainingSettlement);
        $manualDebtDue = max(0.0, $manualDebtTotal - $manualDebtSettled);
        $adminFeeDue = max(0.0, $adminFeeTotal - $adminFeeSettled);

        $calculatedOutstanding = $manualDebtDue + $adminFeeDue;
        if (abs($calculatedOutstanding - $actualOutstanding) > 0.009) {
            $manualDebtDue = min($manualDebtDue, $actualOutstanding);
            $adminFeeDue = max(0.0, $actualOutstanding - $manualDebtDue);
        }

        return [
            'manualDebtTotal' => round($manualDebtTotal, 2),
            'manualDebtSettled' => round($manualDebtSettled, 2),
            'manualDebtDue' => round($manualDebtDue, 2),
            'adminFeeTotal' => round($adminFeeTotal, 2),
            'adminFeeSettled' => round($adminFeeSettled, 2),
            'adminFeeDue' => round($adminFeeDue, 2),
            'totalSettled' => round($totalSettled, 2),
            'outstandingAdministrationDue' => round($actualOutstanding, 2),
        ];
    }

    public function globalSummary(): array
    {
        $workers = Worker::query()->with('deposit')->get();
        $result = [
            'manualDebtTotal' => 0.0,
            'manualDebtSettled' => 0.0,
            'manualDebtDue' => 0.0,
            'adminFeeTotal' => 0.0,
            'adminFeeSettled' => 0.0,
            'adminFeeDue' => 0.0,
            'totalSettled' => 0.0,
            'outstandingAdministrationDue' => 0.0,
        ];

        foreach ($workers as $worker) {
            $summary = $this->summary($worker);
            foreach ($result as $key => $value) {
                $result[$key] = $value + (float) $summary[$key];
            }
        }

        return array_map(static fn (float $value): float => round($value, 2), $result);
    }

    public function outstandingAdministrationDue(Worker $worker): float
    {
        $worker->loadMissing('deposit');

        return round(max(0.0, (float) ($worker->deposit?->debt_balance ?? 0)), 2);
    }
}
