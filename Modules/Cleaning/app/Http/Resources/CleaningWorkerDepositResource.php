<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerFinancialAccountStatusService;

final class CleaningWorkerDepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $depositBalance = max(0.0, (float) $this->current_balance);
        $indebtednessBalance = max(0.0, (float) ($this->debt_balance ?? 0));
        $worker = $this->worker;
        $status = $worker instanceof Worker
            ? app(WorkerFinancialAccountStatusService::class)->status($worker)
            : WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE;
        $summary = $worker instanceof Worker
            ? app(DepositService::class)->depositStatusPayload($worker)
            : [];

        return [
            'workerId' => $this->worker_id,
            'depositBalance' => $depositBalance,
            'currentBalance' => $depositBalance,
            'debtBalance' => $indebtednessBalance,
            'debtAmount' => $indebtednessBalance,
            'indebtednessBalance' => $indebtednessBalance,
            'depositedTotal' => (float) $this->deposited_total,
            'withdrawnTotal' => (float) $this->withdrawn_total,
            'withdrawnAdminRevenueTotal' => (float) ($this->admin_revenue_withdrawn_total ?? 0),
            'allowedDebtLimit' => (float) ($summary['allowedDebtLimit'] ?? max(0.0, (float) ($this->max_negative_balance ?? 0))),
            'configuredAllowedDebtLimit' => (float) ($summary['configuredAllowedDebtLimit'] ?? max(0.0, (float) ($this->max_negative_balance ?? 0))),
            'remainingAllowanceLimit' => (float) ($summary['remainingAllowanceLimit'] ?? 0),
            'availableCommissionCapacity' => (float) ($summary['availableCommissionCapacity'] ?? 0),
            'minimumRequired' => (float) ($summary['minimumRequired'] ?? 0),
            'isAllowanceNearLimit' => (bool) ($summary['isAllowanceNearLimit'] ?? false),
            'allowanceWarningThresholdPercent' => (float) ($summary['allowanceWarningThresholdPercent'] ?? 10),
            'financialWarningCode' => $summary['financialWarningCode'] ?? null,
            'financialWarningMessage' => $summary['financialWarningMessage'] ?? null,
            'status' => $status,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
