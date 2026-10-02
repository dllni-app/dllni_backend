<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class PlatformCoupon extends Model
{
    public const SECTION_CLEANING = 'cleaning';
    public const SECTION_RESTAURANT = 'restaurant';
    public const SECTION_SUPERMARKET = 'supermarket';
    public const SECTION_ALL = 'all';

    public const AUDIENCE_ALL_USERS = 'all_users';
    public const AUDIENCE_SPECIFIC_USERS = 'specific_users';

    public const DISCOUNT_FIXED = 'fixed';
    public const DISCOUNT_PERCENTAGE = 'percentage';

    public const FUNDING_PLATFORM = 'platform';
    public const FUNDING_SHARED = 'shared';
    public const FUNDING_MERCHANT = 'merchant';

    protected $fillable = [
        'code',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'section',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_order_amount',
        'funding_type',
        'platform_funding_percent',
        'merchant_funding_percent',
        'max_platform_funding_amount',
        'cap_platform_funding_to_revenue',
        'audience_type',
        'total_usage_limit',
        'per_user_usage_limit',
        'used_count',
        'starts_at',
        'expires_at',
        'is_active',
        'created_by_user_id',
        'notification_sent_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $coupon): void {
            $coupon->code = Str::upper(trim($coupon->code));

            $fundingType = in_array($coupon->funding_type, [
                self::FUNDING_PLATFORM,
                self::FUNDING_SHARED,
                self::FUNDING_MERCHANT,
            ], true) ? $coupon->funding_type : self::FUNDING_PLATFORM;

            $coupon->funding_type = $fundingType;

            if ($fundingType === self::FUNDING_PLATFORM) {
                $coupon->platform_funding_percent = 100;
                $coupon->merchant_funding_percent = 0;
            } elseif ($fundingType === self::FUNDING_MERCHANT) {
                $coupon->platform_funding_percent = 0;
                $coupon->merchant_funding_percent = 100;
            } else {
                $platformPercent = max(0.0, min(100.0, (float) $coupon->platform_funding_percent));
                $coupon->platform_funding_percent = round($platformPercent, 2);
                $coupon->merchant_funding_percent = round(100 - $platformPercent, 2);
            }

            if ($coupon->max_platform_funding_amount !== null) {
                $coupon->max_platform_funding_amount = max(0.0, (float) $coupon->max_platform_funding_amount);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'platform_coupon_user')
            ->withPivot('created_at');
    }

    public function constraints(): HasMany
    {
        return $this->hasMany(PlatformCouponConstraint::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PlatformCouponRedemption::class);
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->where(fn (Builder $query): Builder => $query->whereNull('total_usage_limit')->orWhereColumn('used_count', '<', 'total_usage_limit'));
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId): void {
            $query->where('audience_type', self::AUDIENCE_ALL_USERS)
                ->orWhere(function (Builder $query) use ($userId): void {
                    $query->where('audience_type', self::AUDIENCE_SPECIFIC_USERS)
                        ->whereHas('users', fn (Builder $query): Builder => $query->whereKey($userId));
                });
        });
    }

    public function localizedTitle(?string $locale = null): string
    {
        return $locale === 'en' && filled($this->title_en) ? (string) $this->title_en : (string) $this->title_ar;
    }

    public function localizedDescription(?string $locale = null): string
    {
        return $locale === 'en' && filled($this->description_en) ? (string) $this->description_en : (string) $this->description_ar;
    }

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'platform_funding_percent' => 'decimal:2',
            'merchant_funding_percent' => 'decimal:2',
            'max_platform_funding_amount' => 'decimal:2',
            'cap_platform_funding_to_revenue' => 'boolean',
            'total_usage_limit' => 'integer',
            'per_user_usage_limit' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'notification_sent_at' => 'datetime',
        ];
    }
}
