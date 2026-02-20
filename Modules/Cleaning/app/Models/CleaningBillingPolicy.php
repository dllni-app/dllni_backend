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

    protected function casts(): array
    {
        return [
            'billing_mode' => CleaningBillingMode::class,
            'rules' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
