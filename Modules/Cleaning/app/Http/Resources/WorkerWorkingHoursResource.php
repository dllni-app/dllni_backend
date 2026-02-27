<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use App\Enums\DayOfWeek;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Worker */
final class WorkerWorkingHoursResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $raw = $this->default_working_hours ?? [];
        $normalized = [];
        foreach (DayOfWeek::values() as $day) {
            $normalized[$day] = $this->normalizeDay($raw[$day] ?? null);
        }

        return [
            'defaultWorkingHours' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>|array<int, array{from: string, to: string}>|bool|null  $value
     * @return array{available: bool, data: array<int, array<string, string>>}
     */
    private function normalizeDay(mixed $value): array
    {
        if ($value === null || $value === false) {
            return ['available' => false, 'data' => []];
        }

        if (isset($value['available'], $value['data']) && is_array($value['data'])) {
            return [
                'available' => (bool) $value['available'],
                'data' => $value['data'],
            ];
        }

        if (is_array($value)) {
            $data = [];
            foreach ($value as $period) {
                if (is_array($period) && isset($period['from'], $period['to'])) {
                    $data[] = [$period['from'] => $period['to']];
                }
            }

            return [
                'available' => count($data) > 0,
                'data' => $data,
            ];
        }

        return ['available' => false, 'data' => []];
    }
}
