<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOrderDispute;

/**
 * @mixin SmOrderDispute
 */
final class SmOrderDisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'order' => SmOrderResource::make($this->whenLoaded('order')),
            'openedByUserId' => $this->opened_by_user_id,
            'openedByUser' => UserResource::make($this->whenLoaded('openedByUser')),
            'ticketNumber' => $this->ticket_number,
            'status' => $this->status?->value,
            'reason' => $this->reason,
            'description' => $this->description,
            'resolvedAt' => $this->resolved_at?->toDateTimeString(),
            'resolvedByUserId' => $this->resolved_by_user_id,
            'resolvedByUser' => UserResource::make($this->whenLoaded('resolvedByUser')),
            'resolutionNotes' => $this->resolution_notes,
            'messages' => SmOrderDisputeMessageResource::collection($this->whenLoaded('messages')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
