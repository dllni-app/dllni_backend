<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Resources\CleaningDepositTransactionResource;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerFinancialAccountStatusService;

final class WorkerDepositController
{
    private const TRANSACTION_TYPE_ALIASES = [
        'deposit' => 'deposit',
        'commission' => 'commission',
        'admin_fee' => 'commission',
        'debt' => 'debt',
        'settlement' => 'settlement',
        'allowance_limit' => 'allowance_limit',
        'allowance_limit_update' => 'allowance_limit',
        'refund' => 'refund',
        'withdrawal' => 'refund',
        'withdraw' => 'refund',
    ];

    public function __construct(
        private readonly DepositService $depositService,
        private readonly AdminCleaningTransactionService $transactionService,
        private readonly WorkerFinancialAccountStatusService $statusService,
    ) {}

    public function getStatus(Request $request): JsonResponse
    {
        $worker = $this->getWorker();
        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $worker->loadMissing('deposit');
        $payload = $this->depositService->depositStatusPayload($worker);
        $financial = $this->transactionService->snapshot($worker);
        $status = $this->statusService->status($worker);
        $isFinancialAccountActive = $this->statusService->isFinancialAccountActive($worker);

        $depositBalance = (float) ($financial['depositBalance'] ?? 0);
        $indebtedness = (float) ($financial['debtBalance'] ?? 0);
        $adminLoan = (float) ($financial['adminLoanBalance'] ?? 0);
        $totalRevenue = (float) ($financial['totalRevenue'] ?? 0);
        $totalCommission = (float) ($financial['totalCommission'] ?? 0);
        $adminCommissionBalance = (float) ($financial['adminCommissionBalance'] ?? 0);

        // Use the exact financial snapshot used by the Filament transaction form so
        // the worker app and dashboard always display the same account values.
        $payload = array_merge($payload, [
            'depositBalance' => $depositBalance,
            'currentBalance' => $depositBalance,
            'debtBalance' => $indebtedness,
            'debtAmount' => $indebtedness,
            'indebtednessBalance' => $indebtedness,
            'manualDebtAmount' => $adminLoan,
            'loanBalance' => $adminLoan,
            'adminLoanBalance' => $adminLoan,
            'adminCommissionDebtAmount' => $indebtedness,
            'totalAdministrationDue' => (float) ($financial['outstandingAdministrationDue'] ?? 0),
            'hasAdminLoan' => $adminLoan > 0,
            'depositedTotal' => (float) ($financial['depositedTotal'] ?? 0),
            'withdrawnTotal' => (float) ($financial['withdrawnTotal'] ?? 0),
            'minimumRequired' => (float) ($financial['minimumRequired'] ?? 0),
            'allowedDebtLimit' => (float) ($financial['allowedDebtLimit'] ?? 0),
            'maxNegativeBalance' => (float) ($financial['maxNegativeBalance'] ?? 0),
            'remainingDebtCapacity' => (float) ($financial['remainingDebtCapacity'] ?? 0),
            'activeReservedCommission' => (float) ($financial['activeReservedCommission'] ?? 0),
            'availableCommissionCapacity' => (float) ($financial['availableCommissionCapacity'] ?? 0),
            'totalRevenue' => $totalRevenue,
            'completedJobs' => (int) ($financial['completedJobs'] ?? 0),
            'totalCommission' => $totalCommission,
            'adminCommissionBalance' => $adminCommissionBalance,
            'grossInvoicesAmount' => round($totalRevenue + $totalCommission, 2),
            'status' => $status,
            'isFinancialAccountActive' => $isFinancialAccountActive,
            'isActive' => $isFinancialAccountActive,
        ]);

        $payload['adminLoanWarning'] = $adminLoan > 0
            ? (app()->isLocale('ar')
                ? 'يتضمن رصيد الإيداع ديناً ممولاً من الإدارة، وسيتم استرداده أولاً عند إغلاق الحساب المالي.'
                : 'The deposit balance includes an administration-funded loan that will be recovered first when the financial account is closed.')
            : null;
        $payload['isEligibleForNewRequests'] = $isFinancialAccountActive
            && $status === WorkerFinancialAccountStatusService::ACTIVE
            && (bool) ($payload['isEligibleForNewRequests'] ?? false);

        return response()->json($payload);
    }

    public function getTransactions(Request $request): JsonResponse
    {
        $worker = $this->getWorker();
        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $perPage = (int) $request->integer('perPage', 20);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 20;
        }

        $type = $this->normalizeTransactionType($request->get('type'));
        $transactions = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->publiclyVisible()
            ->when($type !== null, fn ($query) => $query->forPublicType($type))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => CleaningDepositTransactionResource::collection($transactions)->collection,
            'meta' => [
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'perPage' => $transactions->perPage(),
                'total' => $transactions->total(),
                'filters' => [
                    'requestedType' => $request->get('type'),
                    'appliedType' => $type,
                    'appliedTypes' => $type === null ? [] : [$type],
                ],
            ],
        ]);
    }

    private function normalizeTransactionType(mixed $type): ?string
    {
        if (! is_string($type) || mb_trim($type) === '') {
            return null;
        }

        return self::TRANSACTION_TYPE_ALIASES[mb_trim($type)] ?? null;
    }

    private function getWorker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
