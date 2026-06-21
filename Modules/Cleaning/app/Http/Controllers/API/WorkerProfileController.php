<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Http\Resources\WorkerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Services\DepositService;

final class WorkerProfileController
{
    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    public function __invoke(): WorkerResource|JsonResponse
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            abort(Response::HTTP_FORBIDDEN, 'User must have an associated worker.');
        }

        $worker->load(['user', 'zones.neighborhood', 'availability', 'media', 'deposit']);

        return WorkerResource::make($worker)->additional([
            'isEligibleForNewRequests' => $this->depositService->isWorkerEligibleForNewRequests($worker),
            'depositSummary' => $this->depositService->depositStatusPayload($worker),
        ]);
    }
}
