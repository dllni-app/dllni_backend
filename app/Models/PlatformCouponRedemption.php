<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class PlatformCouponRedemption extends Model
{
    protected $fillable = [
        'platform_coupon_id',
        'user_id',
        'section',
        'order_type',
        'order_id',
        'coupon_code',
        'subtotal',
        'discount_amount',
        'redeemed_at',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PlatformCoupon::class, 'platform_coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'redeemed_at' => 'datetime',
        ];
    }
}
