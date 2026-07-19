<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformCouponConstraint extends Model
{
    public const TYPE_PROPERTY = 'property_type';
    public const TYPE_CLEANING_MODE = 'cleaning_mode';
    public const TYPE_EVENT = 'event_type';

    protected $fillable = [
        'platform_coupon_id',
        'constraint_type',
        'constraint_value',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PlatformCoupon::class, 'platform_coupon_id');
    }
}
