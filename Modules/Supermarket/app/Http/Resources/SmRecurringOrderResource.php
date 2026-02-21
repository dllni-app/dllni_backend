<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmRecurringOrder;

/**
 * @mixin SmRecurringOrder
 */
final class SmRecurringOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'user' => UserResource::make($this->whenLoaded('user')),
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'status' => $this->status?->value ?? $this->status,
            'frequency' => $this->frequency,
            'frequencyConfig' => $this->frequency_config,
            'nextRunAt' => $this->next_run_at?->toDateTimeString(),
            'lastRunAt' => $this->last_run_at?->toDateTimeString(),
            'pausedAt' => $this->paused_at?->toDateTimeString(),
            'items' => SmRecurringOrderItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
