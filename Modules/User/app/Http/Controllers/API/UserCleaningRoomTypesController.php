<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Support\CleaningFinancialDefaults;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class UserCleaningRoomTypesController
{
    public function __invoke(): JsonResponse
    {
        $configured = CleaningRuntimeSettings::financial()->cleaning_room_time_minutes;
        $configured = is_array($configured) ? $configured : [];
        $fallback = CleaningFinancialDefaults::roomTimeMinutes();
        $types = CleaningRoomType::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(static function (CleaningRoomType $type) use ($configured, $fallback): array {
                $bySize = [];
                foreach (CleaningFinancialDefaults::ROOM_SIZES as $size) {
                    $bySize[$size] = [
                        'regularMinutes' => max(1, (int) ($type->suggested_minutes_by_size[$size]['regular'] ?? $configured[$type->code][$size]['regular'] ?? $fallback[$type->code][$size]['regular'] ?? 30)),
                        'deepMinutes' => max(1, (int) ($type->suggested_minutes_by_size[$size]['deep'] ?? $configured[$type->code][$size]['deep'] ?? $fallback[$type->code][$size]['deep'] ?? 60)),
                    ];
                }

                return [
                    'code' => $type->code,
                    'name' => $type->name,
                    'iconKey' => $type->icon_key,
                    'regularHourlyRate' => (float) $type->regular_hourly_rate,
                    'deepHourlyRate' => (float) $type->deep_hourly_rate,
                    'suggestedMinutesBySize' => $bySize,
                ];
            })->values()->all();

        return response()->json(['success' => true, 'data' => $types]);
    }
}
