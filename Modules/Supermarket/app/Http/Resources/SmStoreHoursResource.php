<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SmStoreHoursResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'dayOfWeek' => $this->day_of_week,
            'opensAt' => $this->opens_at,
            'closesAt' => $this->closes_at,
            'isClosed' => $this->is_closed,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
