<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\MediaResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
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
            'emailVerifiedAt' => $this->email_verified_at,
            'password' => $this->password,
            'rememberToken' => $this->remember_token,
            'primaryImage' => MediaResource::make($this->whenLoaded('media', fn() => $this->getFirstMedia('primary-image'))),
            'images' => MediaResource::collection($this->whenLoaded('media', fn() => $this->getMedia('images'))),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}

