<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningBillingPolicy;

/**
 * @mixin CleaningBillingPolicy
 */
final class CleaningBillingPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'billingMode' => $this->billing_mode?->value ?? $this->billing_mode,
            'rules' => $this->rules,
            'isActive' => $this->is_active,
            'isDefault' => $this->is_default,
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
