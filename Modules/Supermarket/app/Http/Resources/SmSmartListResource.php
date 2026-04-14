<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmSmartList;

/**
 * @mixin SmSmartList
 */
final class SmSmartListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'user' => UserResource::make($this->whenLoaded('user')),
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'name' => $this->name,
            'description' => $this->description,
            'isActive' => $this->is_active,
            'schedule' => $this->whenLoaded('schedule', function (): ?array {
                if ($this->schedule === null) {
                    return null;
                }

                return [
                    'frequencyType' => $this->schedule->frequency_type,
                    'dayOfWeek' => $this->schedule->day_of_week,
                    'dayOfMonth' => $this->schedule->day_of_month,
                    'runDate' => $this->schedule->run_date?->toDateString(),
                    'isActive' => $this->schedule->is_active,
                    'nextRunAt' => $this->schedule->next_run_at?->toDateTimeString(),
                    'lastRunAt' => $this->schedule->last_run_at?->toDateTimeString(),
                ];
            }),
            'items' => SmSmartListItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
