<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\UserAddress;

/**
 * @mixin UserAddress
 */
final class UserAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'mobile' => $this->mobile,
            'city' => $this->city,
            'neighborhoodId' => $this->neighborhood_id,
            'neighborhood' => $this->neighborhood,
            'street' => $this->street,
            'building' => $this->building,
            'floor' => $this->floor,
            'directions' => $this->directions,
            'governorate' => $this->city,
            'area' => $this->neighborhood,
            'addressDetails' => $this->directions,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'isDefault' => $this->is_default,
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
