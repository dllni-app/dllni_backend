<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Enums\OfferListingUrgency;
use Modules\Resturants\Enums\OfferRewardType;
use Modules\Resturants\Enums\OfferType;
use Modules\Resturants\Traits\FilterQueries\OfferFilterQuery;

final class Offer extends Model
{
    use HasFactory;
    use OfferFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'starts_at',
        'ends_at',
        'is_active',
        'offer_type',
        'buy_quantity',
        'reward_quantity',
        'reward_type',
        'reward_discount_percent',
        'is_repeatable',
        'max_applications_per_order',
        'rule_version',
        'show_upcoming',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'offer_product')
            ->withTimestamps();
    }

    public function rewardProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'restaurant_offer_reward_products')
            ->withTimestamps();
    }

    public function promotionApplications(): HasMany
    {
        return $this->hasMany(RestaurantOrderPromotionApplication::class, 'original_offer_id');
    }

    public function isProductDiscount(): bool
    {
        return $this->offer_type === null || $this->offer_type === OfferType::ProductDiscount;
    }

    public function scopeActiveAt(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= CarbonImmutable::now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function effectiveStatus(?CarbonInterface $at = null): string
    {
        $at ??= CarbonImmutable::now();
        if (! $this->is_active) {
            return 'paused';
        }
        if ($this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($at)) {
            return 'expired';
        }
        if ($this->starts_at !== null && $this->starts_at->greaterThan($at)) {
            return 'scheduled';
        }

        return 'active';
    }

    public function promotionAttributes(): array
    {
        return [
            'offerType' => $this->offer_type?->value ?? OfferType::ProductDiscount->value,
            'buyQuantity' => $this->buy_quantity,
            'rewardQuantity' => $this->reward_quantity,
            'rewardType' => $this->reward_type?->value,
            'rewardDiscountPercent' => $this->reward_discount_percent === null ? null : (float) $this->reward_discount_percent,
            'isRepeatable' => (bool) $this->is_repeatable,
            'maxApplicationsPerOrder' => $this->max_applications_per_order,
            'ruleVersion' => (int) ($this->rule_version ?? 1),
            'status' => $this->effectiveStatus(),
            'showUpcoming' => (bool) $this->show_upcoming,
            'badgeText' => $this->listingBadgeText(),
            'description' => $this->description,
        ];
    }

    public function listingUrgencyTag(): ?OfferListingUrgency
    {
        $endsAt = $this->ends_at;

        if ($endsAt === null) {
            return null;
        }

        $now = CarbonImmutable::now();

        if ($endsAt->lessThanOrEqualTo($now)) {
            return null;
        }

        if ($endsAt->isToday()) {
            return OfferListingUrgency::TodaysOffer;
        }

        if ($endsAt->lessThanOrEqualTo($now->addHours(48))) {
            return OfferListingUrgency::EndingSoon;
        }

        return OfferListingUrgency::LimitedTime;
    }

    public function listingBadgeText(): ?string
    {
        if (! $this->isProductDiscount()) {
            if (! $this->buy_quantity || ! $this->reward_quantity || ! $this->reward_discount_percent) {
                return null;
            }
            $quantity = $this->buy_quantity.'+'.$this->reward_quantity;
            $percent = (float) $this->reward_discount_percent;

            return $percent === 100.0 ? $quantity : $quantity.' ('.$percent.'%)';
        }

        if ($this->discount_value === null) {
            return null;
        }

        $value = (float) $this->discount_value;
        $formattedValue = mb_rtrim(mb_rtrim(number_format($value, 2, '.', ''), '0'), '.');
        $discountType = $this->discount_type;
        $discountTypeValue = $discountType instanceof DiscountType ? $discountType->value : (string) $discountType;

        return match ($discountTypeValue) {
            DiscountType::Percentage->value, 'percent', 'percentage_discount' => $formattedValue.'%',
            DiscountType::FixedAmount->value, 'fixed', 'amount', 'fixed_discount' => $formattedValue,
            default => $formattedValue,
        };
    }

    protected static function newFactory(): OfferFactory
    {
        return OfferFactory::new();
    }

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'offer_type' => OfferType::class,
            'reward_type' => OfferRewardType::class,
            'buy_quantity' => 'integer',
            'reward_quantity' => 'integer',
            'reward_discount_percent' => 'decimal:2',
            'is_repeatable' => 'boolean',
            'max_applications_per_order' => 'integer',
            'rule_version' => 'integer',
            'show_upcoming' => 'boolean',
        ];
    }
}