<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Requests\WorkerAccountStatusRequest;
use Modules\Cleaning\Services\DepositService;

final class WorkerAccountStatusController
{
    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    public function show(): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $worker->loadMissing('deposit');
        $depositSummary = $this->depositService->depositStatusPayload($worker);

        return response()->json([
            'isActive' => (bool) $worker->is_active,
            'isSuspended' => (bool) $worker->is_suspended,
            'suspendedUntil' => $worker->suspended_until?->toDateTimeString(),
            'isEligibleForNewRequests' => $depositSummary['isEligibleForNewRequests'],
            'depositSummary' => $depositSummary,
        ]);
    }

    public function update(WorkerAccountStatusRequest $request): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $worker->update([
            'is_active' => $request->validated()['isActive'],
        ]);

        return $this->show();
    }

    private function worker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
