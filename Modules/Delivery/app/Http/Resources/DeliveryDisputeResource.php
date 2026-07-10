<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Delivery\Models\DeliveryOrder;

/**
 * @mixin Dispute
 */
final class DeliveryDisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->resource->relationLoaded('booking') ? $this->resource->getRelation('booking') : null;
        $order = $booking instanceof DeliveryOrder ? $booking : null;
        $trustImpactPoints = $this->resource->relationLoaded('trustLogs')
            ? (int) $this->resource->trustLogs->sum(fn ($log): int => (int) $log->score_delta)
            : 0;

        return [
            'id' => $this->id,
            'orderId' => $this->booking_id,
            'orderNumber' => $order?->order_number,
            'status' => $this->status?->value ?? $this->status,
            'statusLabel' => $this->status?->label() ?? (string) ($this->status?->value ?? $this->status ?? ''),
            'category' => $this->category?->value ?? $this->category,
            'categoryLabel' => $this->category?->label() ?? (string) ($this->category?->value ?? $this->category ?? ''),
            'resolution' => $this->resolution?->value ?? $this->resolution,
            'resolutionLabel' => $this->resolution?->label(),
            'ticketNumber' => $this->ticket_number,
            'description' => $this->description,
            'trustImpactPoints' => $trustImpactPoints,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
