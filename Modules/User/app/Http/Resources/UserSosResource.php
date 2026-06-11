<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use App\Models\SosAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SosAlert
 */
final class UserSosResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'message' => $this->message,
            'status' => $this->status?->value ?? $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
