<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Delivery\Models\DeliveryOrderEvent;

/**
 * @mixin DeliveryOrderEvent
 */
final class DeliveryOrderEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fromStatus' => $this->from_status,
            'toStatus' => $this->to_status,
            'note' => $this->note,
            'payload' => $this->payload,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
