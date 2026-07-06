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

final class WorkerDepositController
{
    /** @var array<string, string> */
    private const TRANSACTION_TYPE_ALIASES = [
        'deposit' => 'deposit',
        'withdrawal' => 'withdrawal',
        'withdraw' => 'withdrawal',
        'admin_fee' => 'admin_fee',
        'debt' => 'admin_fee',
        'settlement' => 'settlement',
        'refund' => 'refund',
        'adjustment' => 'adjustment',
    ];

    public function __construct(
        private readonly DepositService $depositService
    ) {}

    public function getStatus(Request $request): JsonResponse
    {
        $worker = $this->getWorker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $worker->loadMissing('deposit');
        $payload = $this->depositService->depositStatusPayload($worker);
        $payload['debtAmount'] = $this->resolveDebtAmount($worker, $payload);

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

        $query = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->orderByDesc('created_at');

        if ($type !== null) {
            $query->where('type', $type);
        }

        $transactions = $query->paginate($perPage);

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
                ],
            ],
        ]);
    }

    private function resolveDebtAmount(Worker $worker, array $payload): float
    {
        $totals = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'admin_fee' THEN amount ELSE 0 END), 0) as admin_fee_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN amount ELSE 0 END), 0) as settlement_total")
            ->first();

        $transactionDebt = max(
            0.0,
            (float) ($totals?->admin_fee_total ?? 0) - (float) ($totals?->settlement_total ?? 0),
        );

        if ($transactionDebt > 0) {
            return round($transactionDebt, 2);
        }

        $depositBase = (float) ($payload['depositedTotal'] ?? 0) - (float) ($payload['withdrawnTotal'] ?? 0);
        $debtAmount = $depositBase - (float) ($payload['currentBalance'] ?? 0);

        return round(max(0.0, $debtAmount), 2);
    }

    private function normalizeTransactionType(mixed $type): ?string
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        return self::TRANSACTION_TYPE_ALIASES[trim($type)] ?? null;
    }

    private function getWorker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
