<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryDriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'companyId' => $this->company_id,
            'firstName' => $this->first_name,
            'phone' => $this->phone,
            'vehicleType' => $this->vehicle_type,
            'plateNumber' => $this->plate_number,
            'availabilityStatus' => $this->availability_status,
            'isActive' => $this->is_active,
            'isSuspended' => $this->is_suspended,
            'trustScore' => $this->trust_score,
            'openDisputesCount' => $this->open_disputes_count,
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
