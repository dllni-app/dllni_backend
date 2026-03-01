<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Traits\FilterQueries\CleaningBillingPolicyFilterQuery;

final class CleaningBillingPolicy extends Model
{
    use CleaningBillingPolicyFilterQuery;

    protected $fillable = [
        'name',
        'billing_mode',
        'rules',
        'is_active',
        'is_default',
    ];

    public function casts(): array
    {
        return [
            'billing_mode' => CleaningBillingMode::class,
            'rules' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function getMinBillableMinutesAttribute(): ?int
    {
        $rules = $this->rules ?? [];

        return isset($rules['min_billable_minutes']) ? (int) $rules['min_billable_minutes'] : null;
    }

    /**
     * @param  int|string|null  $value
     */
    public function setMinBillableMinutesAttribute(mixed $value): void
    {
        $rules = $this->rules ?? [];
        if ($value !== null && $value !== '') {
            $rules['min_billable_minutes'] = (int) $value;
        } else {
            unset($rules['min_billable_minutes']);
        }
        $this->rules = $rules;
    }
}
