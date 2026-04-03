<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\OperatingHoursRequest;
use Modules\Resturants\Models\OperatingHour;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOperatingHoursController
{
    public function show(RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();

        $restaurant->load('operatingHours');

        $dailyHours = [];
        foreach (\Modules\Resturants\Enums\DayOfWeek::cases() as $day) {
            $dayHours = $restaurant->operatingHours->where('day_of_week', $day);
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
                'isTemporarilyClosed' => (bool) $restaurant->is_temporarily_closed,
                'dailyHours' => $dailyHours,
            ],
        ]);
    }

    public function update(OperatingHoursRequest $request, RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();

        $validated = $request->validated();

        $restaurant->update([
            'is_temporarily_closed' => $validated['isTemporarilyClosed'] ?? false,
        ]);

        OperatingHour::query()->where('restaurant_id', $restaurant->id)->delete();

        foreach ($validated['dailyHours'] ?? [] as $dayConfig) {
            $dayOfWeek = $dayConfig['dayOfWeek'] ?? null;
            $isEnabled = $dayConfig['isEnabled'] ?? false;
            $timeSlots = $dayConfig['timeSlots'] ?? [];

            if (! $dayOfWeek) {
                continue;
            }

            if (! $isEnabled || empty($timeSlots)) {
                OperatingHour::create([
                    'restaurant_id' => $restaurant->id,
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
                    OperatingHour::create([
                        'restaurant_id' => $restaurant->id,
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
