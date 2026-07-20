<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Resources\CleaningDepositTransactionResource;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerDebtService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;

final class WorkerDepositController
{
    private const TRANSACTION_TYPE_ALIASES = [
        'deposit' => 'deposit',
        'commission' => 'commission',
        'admin_fee' => 'commission',
        'debt' => 'debt',
        'settlement' => 'settlement',
        'refund' => 'refund',
        'withdrawal' => 'refund',
        'withdraw' => 'refund',
    ];

    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerDebtService $debtService,
        private readonly WorkerOrderSolvencyService $solvencyService,
    ) {}

    public function getStatus(Request $request): JsonResponse
    {
        $worker = $this->getWorker();
        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $worker->loadMissing('deposit');
        $payload = $this->depositService->depositStatusPayload($worker);
        $debtSummary = $this->debtService->summary($worker);
        $capacity = $this->solvencyService->workerCapacitySummary($worker);

        $indebtedness = (float) $debtSummary['indebtednessBalance'];
        $adminLoan = (float) $debtSummary['adminLoanBalance'];

        // Compatibility aliases remain additive, while the explicit fields define the business meaning.
        $payload['debtBalance'] = $indebtedness;
        $payload['debtAmount'] = $indebtedness;
        $payload['indebtednessBalance'] = $indebtedness;
        $payload['manualDebtAmount'] = $adminLoan;
        $payload['loanBalance'] = $adminLoan;
        $payload['adminLoanBalance'] = $adminLoan;
        $payload['adminCommissionDebtAmount'] = $indebtedness;
        $payload['totalAdministrationDue'] = (float) $debtSummary['outstandingAdministrationDue'];
        $payload['hasAdminLoan'] = $adminLoan > 0;
        $payload['adminLoanWarning'] = $adminLoan > 0
            ? (app()->isLocale('ar')
                ? 'يتضمن رصيد الإيداع ديناً ممولاً من الإدارة، وسيتم استرداده أولاً عند إغلاق الحساب المالي.'
                : 'The deposit balance includes an administration-funded loan that will be recovered first when the financial account is closed.')
            : null;
        $payload['activeReservedCommission'] = $capacity['activeReservedCommission'];
        $payload['availableCommissionCapacity'] = $capacity['availableCommissionCapacity'];
        $payload['isFinancialAccountActive'] = (bool) ($worker->deposit?->is_active ?? true);

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
