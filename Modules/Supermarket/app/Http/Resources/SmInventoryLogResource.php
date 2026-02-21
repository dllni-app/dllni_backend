<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmInventoryLog;

/**
 * @mixin SmInventoryLog
 */
final class SmInventoryLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'product' => SmProductResource::make($this->whenLoaded('product')),
            'type' => $this->type,
            'quantityChange' => $this->quantity_change,
            'quantityAfter' => $this->quantity_after,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'notes' => $this->notes,
            'userId' => $this->user_id,
            'user' => UserResource::make($this->whenLoaded('user')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
