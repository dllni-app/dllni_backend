<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOrderDisputeMessage;

/**
 * @mixin SmOrderDisputeMessage
 */
final class SmOrderDisputeMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'disputeId' => $this->dispute_id,
            'dispute' => SmOrderDisputeResource::make($this->whenLoaded('dispute')),
            'userId' => $this->user_id,
            'user' => UserResource::make($this->whenLoaded('user')),
            'message' => $this->message,
            'isInternal' => $this->is_internal,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
