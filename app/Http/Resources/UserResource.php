<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phoneVerifiedAt' => $this->phone_verified_at,
            'moduleType' => $this->module_type?->value,
            'workerId' => $this->when(
                $this->relationLoaded('worker'),
                fn () => $this->worker?->id
            ),
            'workerPreferredWorkType' => $this->when(
                $this->relationLoaded('worker'),
                fn () => $this->worker?->preferred_work_type?->value ?? $this->worker?->preferred_work_type ?? 'both'
            ),
            'emailVerifiedAt' => $this->email_verified_at,
            'primaryImage' => MediaResource::make($this->whenLoaded('media', fn () => $this->getFirstMedia('primary-image'))),
            'images' => MediaResource::collection($this->whenLoaded('media', fn () => $this->getMedia('images'))),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
