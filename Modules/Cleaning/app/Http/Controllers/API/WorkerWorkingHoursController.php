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

        $hours = $request->validated()['defaultWorkingHours'];
        $normalized = [];
        foreach (array_keys($hours) as $day) {
            $v = $hours[$day];
            $normalized[$day] = [
                'available' => (bool) ($v['available'] ?? false),
                'data' => isset($v['data']) && is_array($v['data']) ? $v['data'] : [],
            ];
        }
        $worker->update(['default_working_hours' => $normalized]);

        return WorkerWorkingHoursResource::make($worker->fresh());
    }

    private function worker(): ?Worker
    {
        $user = auth()->user();

        return $user?->worker;
    }
}
