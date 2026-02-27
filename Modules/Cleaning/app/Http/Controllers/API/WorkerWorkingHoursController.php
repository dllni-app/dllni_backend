<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Requests\WorkerWorkingHoursRequest;
use Modules\Cleaning\Http\Resources\WorkerWorkingHoursResource;

final class WorkerWorkingHoursController
{
    public function show(): WorkerWorkingHoursResource|JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        return WorkerWorkingHoursResource::make($worker);
    }

    public function update(WorkerWorkingHoursRequest $request): WorkerWorkingHoursResource|JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $worker->update([
            'default_working_hours' => $request->validated()['defaultWorkingHours'],
        ]);

        return WorkerWorkingHoursResource::make($worker->fresh());
    }

    private function worker(): ?Worker
    {
        $user = auth()->user();

        return $user?->worker;
    }
}
