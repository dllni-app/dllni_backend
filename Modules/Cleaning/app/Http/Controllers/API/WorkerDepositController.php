<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\CleaningDepositSetting;
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

        $deposit = $worker->deposit;

        if (! $deposit) {
            $setting = CleaningDepositSetting::first();

            return response()->json([
                'workerId' => $worker->id,
                'currentBalance' => 0,
                'depositedTotal' => 0,
                'withdrawnTotal' => 0,
                'minimumRequired' => (float) ($setting?->minimum_deposit_amount ?? 0),
                'status' => 'active',
                'exceedanceAmount' => null,
                'isEligibleForNewRequests' => true,
                'createdAt' => null,
                'updatedAt' => null,
            ]);
        }

        $setting = CleaningDepositSetting::first();
        $isEligible = $this->depositService->isWorkerEligibleForNewRequests($worker);
        $exceedance = $this->depositService->calculateWorkerRevenueExceedance($worker);

        return response()->json([
            'workerId' => $worker->id,
            'currentBalance' => (float) $deposit->current_balance,
            'depositedTotal' => (float) $deposit->deposited_total,
            'withdrawnTotal' => (float) $deposit->withdrawn_total,
            'minimumRequired' => (float) ($setting?->minimum_deposit_amount ?? 0),
            'status' => $worker->security_deposit_status,
            'exceedanceAmount' => $exceedance,
            'isEligibleForNewRequests' => $isEligible,
            'createdAt' => $deposit->created_at?->toIso8601String(),
            'updatedAt' => $deposit->updated_at?->toIso8601String(),
        ]);
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

        $type = $request->get('type'); // Optional filter: 'deposit' or 'withdrawal'

        $query = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->orderByDesc('created_at');

        if ($type && in_array($type, ['deposit', 'withdrawal'])) {
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

    private function getWorker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
