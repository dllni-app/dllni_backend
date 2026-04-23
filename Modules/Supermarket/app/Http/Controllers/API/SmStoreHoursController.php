<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Enums\DayOfWeek;
use Modules\Supermarket\Http\Requests\SmStoreHoursRequest;
use Modules\Supermarket\Models\SmStoreHours;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class SmStoreHoursController
{
    public function show(StoreOwnerContextService $context): JsonResponse
    {
        $store = $context->ownedStore();
        $store->load('storeHours');

        $dailyHours = [];
        foreach (DayOfWeek::cases() as $day) {
            $dayHours = $store->storeHours->where('day_of_week', $day);
            $timeSlots = $dayHours
                ->filter(fn ($h) => ! $h->is_closed && $h->open_time && $h->close_time)
                ->map(fn ($h) => [
                    'startTime' => \Carbon\Carbon::parse($h->open_time)->format('h:i A'),
                    'endTime' => \Carbon\Carbon::parse($h->close_time)->format('h:i A'),
                ])
                ->values()
                ->all();

            $isEnabled = $dayHours->contains(fn ($h) => ! $h->is_closed);

            $dailyHours[] = [
                'dayOfWeek' => $day->value,
                'isEnabled' => $isEnabled,
                'timeSlots' => $timeSlots,
            ];
        }

        return response()->json([
            'data' => [
                'isTemporarilyClosed' => (bool) $store->is_temporarily_closed,
                'dailyHours' => $dailyHours,
            ],
        ]);
    }

    public function update(SmStoreHoursRequest $request, StoreOwnerContextService $context): JsonResponse
    {
        $store = $context->ownedStore();
        $validated = $request->validated();

        $store->update([
            'is_temporarily_closed' => $validated['isTemporarilyClosed'] ?? false,
        ]);

        SmStoreHours::query()->where('store_id', $store->id)->delete();

        foreach ($validated['dailyHours'] ?? [] as $dayConfig) {
            $dayOfWeek = $dayConfig['dayOfWeek'] ?? null;
            $isEnabled = $dayConfig['isEnabled'] ?? false;
            $timeSlots = $dayConfig['timeSlots'] ?? [];

            if (! $dayOfWeek) {
                continue;
            }

            if (! $isEnabled || empty($timeSlots)) {
                SmStoreHours::create([
                    'store_id' => $store->id,
                    'day_of_week' => $dayOfWeek,
                    'open_time' => null,
                    'close_time' => null,
                    'is_closed' => true,
                ]);

                continue;
            }

            foreach ($timeSlots as $slot) {
                $startTime = $slot['startTime'] ?? null;
                $endTime = $slot['endTime'] ?? null;

                if ($startTime && $endTime) {
                    SmStoreHours::create([
                        'store_id' => $store->id,
                        'day_of_week' => $dayOfWeek,
                        'open_time' => \Carbon\Carbon::parse($startTime)->format('H:i:s'),
                        'close_time' => \Carbon\Carbon::parse($endTime)->format('H:i:s'),
                        'is_closed' => false,
                    ]);
                }
            }
        }

        return $this->show($context);
    }
}
