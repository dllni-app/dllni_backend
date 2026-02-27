<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Worker */
final class WorkerWorkingHoursResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'defaultWorkingHours' => $this->resource->getNormalizedDefaultWorkingHours(),
        ];
    }
}
