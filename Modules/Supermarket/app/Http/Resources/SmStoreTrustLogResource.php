<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SmStoreTrustLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'eventType' => $this->event_type,
            'scoreDelta' => $this->score_delta,
            'scoreAfter' => $this->score_after,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'notes' => $this->notes,
            'triggeredByUserId' => $this->triggered_by_user_id,
            'triggeredByUser' => UserResource::make($this->whenLoaded('triggeredByUser')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
