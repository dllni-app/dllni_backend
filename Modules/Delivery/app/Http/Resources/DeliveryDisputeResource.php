<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispute
 */
final class DeliveryDisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->booking_id,
            'status' => $this->status?->value ?? $this->status,
            'category' => $this->category?->value ?? $this->category,
            'resolution' => $this->resolution?->value ?? $this->resolution,
            'ticketNumber' => $this->ticket_number,
            'description' => $this->description,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
