<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Requests\WorkerAccountStatusRequest;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerDispatchEligibilityService;

final class WorkerAccountStatusController
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerDispatchEligibilityService $dispatchEligibility,
    ) {}

    public function show(): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $worker->loadMissing('deposit');
        $gate = $this->dispatchEligibility->forNewRequests($worker);
        $depositSummary = $gate['depositSummary'];
        $canReceive = $gate['canReceiveNewRequests'];

        return response()->json([
            'isActive' => (bool) $worker->is_active,
            'isSuspended' => (bool) $worker->is_suspended,
            'suspendedUntil' => $worker->suspended_until?->toDateTimeString(),
            'isEligibleForNewRequests' => $canReceive,
            'depositSummary' => $depositSummary,
            'dispatchEligibility' => $gate,
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
