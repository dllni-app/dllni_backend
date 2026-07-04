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
        $payload['debtAmount'] = $this->resolveDebtAmount($payload);

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

        $type = $request->get('type');
        $allowedTypes = ['deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment'];

        $query = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->orderByDesc('created_at');

        if ($type && in_array($type, $allowedTypes, true)) {
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
            ],
        ]);
    }

    /**
     * Debt is the admin amount still owed by the worker.
     */
    private function resolveDebtAmount(array $payload): float
    {
        $depositBase = (float) ($payload['depositedTotal'] ?? 0) - (float) ($payload['withdrawnTotal'] ?? 0);
        $debtAmount = $depositBase - (float) ($payload['currentBalance'] ?? 0);

        return round(max(0.0, $debtAmount), 2);
    }

    private function getWorker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
