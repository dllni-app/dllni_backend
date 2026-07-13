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

final class WorkerDepositController
{
    /** @var array<string, string> */
    private const TRANSACTION_TYPE_ALIASES = [
        'deposit' => 'deposit',
        'debt' => 'debt',
        'admin_fee' => 'debt',
        'settlement' => 'settlement',
        'refund' => 'refund',
        'withdrawal' => 'refund',
        'withdraw' => 'refund',
    ];

    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerDebtService $debtService,
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

        // Keep the existing debtAmount key for Flutter compatibility while making
        // the formula explicit: platform-funded debt + unpaid admin commission.
        $payload['debtAmount'] = $debtSummary['outstandingAdministrationDue'];
        $payload['manualDebtAmount'] = $debtSummary['manualDebtDue'];
        $payload['adminCommissionDebtAmount'] = $debtSummary['adminFeeDue'];

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
            ->publiclyVisible()
            ->when(
                $type !== null,
                fn ($query) => $query->forPublicType($type),
            )
            ->orderByDesc('created_at');

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
                    'appliedTypes' => $type === null ? [] : [$type],
                ],
            ],
        ]);
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
