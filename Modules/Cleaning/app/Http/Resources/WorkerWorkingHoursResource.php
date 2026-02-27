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
            $normalized[$day] = $raw[$day] ?? false;
        }

        return [
            'defaultWorkingHours' => $normalized,
        ];
    }
}
