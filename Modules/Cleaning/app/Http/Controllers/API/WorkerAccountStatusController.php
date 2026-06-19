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
        $canReceive = (bool) $depositSummary['isEligibleForNewRequests'];
        $reasonCode = $this->reasonCode($worker, $depositSummary, $canReceive);
        $gate = [
            'canReceiveNewRequests' => $canReceive,
            'canAcceptNewBookings' => $canReceive,
            'reasonCode' => $reasonCode,
            'message' => $this->messageFor($reasonCode),
            'depositSummary' => $depositSummary,
        ];

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

    /** @param array<string, mixed> $depositSummary */
    private function reasonCode(Worker $worker, array $depositSummary, bool $canReceive): string
    {
        if (! $worker->is_active) {
            return 'worker_inactive';
        }

        if ($worker->is_suspended) {
            return 'worker_suspended';
        }

        if ($canReceive) {
            return 'eligible';
        }

        if (($depositSummary['exceedanceAmount'] ?? null) !== null) {
            return 'deposit_below_allowed_balance';
        }

        return 'trust_score_too_low';
    }

    private function messageFor(string $reasonCode): string
    {
        return match ($reasonCode) {
            'eligible' => 'Your account can receive and accept new requests.',
            'worker_inactive' => 'Your account is inactive. Reactivate your account to receive new requests.',
            'worker_suspended' => 'Your account is suspended. Please contact support for more details.',
            'deposit_below_allowed_balance' => 'Your deposit balance is below the allowed limit. Please recharge your deposit account to receive new requests.',
            'trust_score_too_low' => 'Your trust score is below the minimum required to receive new requests.',
            default => 'Your account cannot receive new requests right now.',
        };
    }
}
