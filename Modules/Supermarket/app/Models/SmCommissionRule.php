<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Traits\FilterQueries\SmCommissionRuleFilterQuery;

final class SmCommissionRule extends Model
{
    use SmCommissionRuleFilterQuery;

    protected $table = 'sm_commission_rules';

    protected $fillable = [
        'store_id',
        'commission_type',
        'value',
        'min_order_amount',
        'max_commission_amount',
        'starts_at',
        'ends_at',
        'is_active',
        'is_default',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    protected function casts(): array
    {
        return [
            'commission_type' => SmCommissionType::class,
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_commission_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
