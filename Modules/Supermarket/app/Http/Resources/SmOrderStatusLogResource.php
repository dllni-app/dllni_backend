<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOrderStatusLog;

/**
 * @mixin SmOrderStatusLog
 */
final class SmOrderStatusLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'order' => SmOrderResource::make($this->whenLoaded('order')),
            'fromStatus' => $this->from_status,
            'toStatus' => $this->to_status,
            'notes' => $this->notes,
            'changedByUserId' => $this->changed_by_user_id,
            'changedByUser' => UserResource::make($this->whenLoaded('changedByUser')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
