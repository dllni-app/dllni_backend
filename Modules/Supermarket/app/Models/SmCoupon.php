<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Supermarket\Traits\FilterQueries\SmCouponFilterQuery;

final class SmCoupon extends Model
{
    use SmCouponFilterQuery;

    protected $table = 'sm_coupons';

    protected $fillable = [
        'store_id',
        'code',
        'type',
        'value',
        'percent',
        'min_order_amount',
        'max_discount_amount',
        'usage_limit',
        'used_count',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SmOrder::class, 'coupon_id');
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
