<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Requests\WorkerAccountStatusRequest;

final class WorkerAccountStatusController
{
    public function show(): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'isActive' => (bool) $worker->is_active,
            'isSuspended' => (bool) $worker->is_suspended,
            'suspendedUntil' => $worker->suspended_until?->toDateTimeString(),
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
