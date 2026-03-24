<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Http\Requests\WorkerWorkAreasRequest;

final class WorkerWorkAreasController
{
    public function show(): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'zones' => $worker->zones()
                ->orderBy('id')
                ->get(['id', 'name', 'is_active'])
                ->map(fn ($zone): array => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'isActive' => (bool) $zone->is_active,
                ])->all(),
        ]);
    }

    public function update(WorkerWorkAreasRequest $request): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $zonesPayload = $request->validated()['zones'];

        DB::transaction(function () use ($worker, $zonesPayload): void {
            $requestedNames = collect($zonesPayload)
                ->map(static fn (array $zone): string => (string) $zone['name'])
                ->all();

            $worker->zones()->whereNotIn('name', $requestedNames)->delete();

            foreach ($zonesPayload as $zoneData) {
                $worker->zones()->updateOrCreate(
                    ['name' => (string) $zoneData['name']],
                    ['is_active' => (bool) ($zoneData['isActive'] ?? true)]
                );
            }
        });

        return $this->show();
    }

    private function worker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
