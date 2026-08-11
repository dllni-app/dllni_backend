<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Http\Requests\WorkerWorkAreasRequest;
use Modules\Cleaning\Services\CleaningNeighborhoodResolver;

final class WorkerWorkAreasController
{
    public function __construct(
        private readonly CleaningNeighborhoodResolver $neighborhoodResolver,
    ) {}

    public function show(): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'zones' => $worker->zones()
                ->with('neighborhood')
                ->orderBy('name')
                ->get(['id', 'worker_id', 'neighborhood_id', 'name', 'is_active'])
                ->map(fn ($zone): array => [
                    'id' => $zone->id,
                    'neighborhoodId' => $zone->neighborhood_id !== null ? (int) $zone->neighborhood_id : null,
                    'name' => $zone->name,
                    'cityName' => $zone->neighborhood?->city_name,
                    'nameAr' => $zone->neighborhood?->name_ar,
                    'nameEn' => $zone->neighborhood?->name_en,
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
        $resolvedZones = collect($zonesPayload)
            ->map(function (array $zoneData, int $index): array {
                $neighborhood = $this->neighborhoodResolver->resolve(
                    isset($zoneData['neighborhoodId']) ? (int) $zoneData['neighborhoodId'] : null,
                    $zoneData['name'] ?? null,
                );

                if ($neighborhood === null) {
                    throw ValidationException::withMessages([
                        "zones.{$index}.neighborhoodId" => ["\u{627}\u{644}\u{62d}\u{64a} \u{627}\u{644}\u{645}\u{62d}\u{62f}\u{62f} \u{63a}\u{64a}\u{631} \u{645}\u{648}\u{62c}\u{648}\u{62f} \u{623}\u{648} \u{63a}\u{64a}\u{631} \u{645}\u{641}\u{639}\u{644}."],
                    ]);
                }

                return [
                    'neighborhood' => $neighborhood,
                    'isActive' => (bool) ($zoneData['isActive'] ?? true),
                ];
            })
            ->keyBy(fn (array $zone): int => (int) $zone['neighborhood']->id)
            ->values();

        DB::transaction(function () use ($worker, $resolvedZones): void {
            $selectedIds = $resolvedZones
                ->map(fn (array $zone): int => (int) $zone['neighborhood']->id)
                ->all();

            $worker->zones()
                ->where(function ($query) use ($selectedIds): void {
                    $query->whereNull('neighborhood_id')
                        ->orWhereNotIn('neighborhood_id', $selectedIds);
                })
                ->delete();

            foreach ($resolvedZones as $zoneData) {
                $worker->zones()->updateOrCreate(
                    ['neighborhood_id' => (int) $zoneData['neighborhood']->id],
                    [
                        'name' => (string) $zoneData['neighborhood']->name_ar,
                        'is_active' => (bool) $zoneData['isActive'],
                    ]
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
