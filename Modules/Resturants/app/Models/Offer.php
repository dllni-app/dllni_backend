<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Enums\OfferListingUrgency;
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
        $discountType = $this->discount_type;

        return match ($discountType) {
            DiscountType::Percentage => $this->discount_value !== null
                ? mb_rtrim(mb_rtrim(number_format((float) $this->discount_value, 2, '.', ''), '0'), '.') . '%'
                : null,
            DiscountType::FixedAmount => $this->discount_value !== null
                ? mb_rtrim(mb_rtrim(number_format((float) $this->discount_value, 2, '.', ''), '0'), '.')
                : null,
            null => null,
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
        ];
    }
}
