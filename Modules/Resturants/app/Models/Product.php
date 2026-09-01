<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Traits\FilterQueries\ProductFilterQuery;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class Product extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use ProductFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'category_id',
        'name',
        'description',
        'price',
        'discounted_price',
        'is_available',
        'unavailable_until',
        'availability_note',
        'stock_quantity',
        'low_stock_threshold',
        'preparation_time',
        'is_featured',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'modifier_group_product')
            ->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function substitutions(): HasMany
    {
        return $this->hasMany(RestaurantProductSubstitution::class, 'product_id');
    }

    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'inventory_item_product')
            ->withPivot('quantity_used')
            ->withTimestamps();
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'offer_product')
            ->withTimestamps();
    }

    /**
     * Return the price the customer must actually pay right now.
     *
     * The best price wins between the product-level discounted_price and all
     * currently active offers attached to the product. Percentage offers are
     * capped at 100% and fixed discounts never make the price negative.
     */
    public function effectivePrice(): float
    {
        $originalPrice = max(0.0, (float) ($this->price ?? 0));
        $bestPrice = $originalPrice;

        if ($this->discounted_price !== null) {
            $bestPrice = min($bestPrice, max(0.0, (float) $this->discounted_price));
        }

        foreach ($this->activeOffersNow() as $offer) {
            $discountValue = max(0.0, (float) $offer->discount_value);
            $candidate = match ($offer->discount_type) {
                DiscountType::Percentage => $originalPrice * (1 - min(100.0, $discountValue) / 100),
                DiscountType::FixedAmount => $originalPrice - $discountValue,
            };

            $bestPrice = min($bestPrice, max(0.0, $candidate));
        }

        return round($bestPrice, 2);
    }

    public function effectiveDiscountedPrice(): ?float
    {
        $originalPrice = max(0.0, (float) ($this->price ?? 0));
        $effectivePrice = $this->effectivePrice();

        return $effectivePrice < $originalPrice ? $effectivePrice : null;
    }

    /** @return Collection<int, Offer> */
    public function activeOffersNow(): Collection
    {
        $offers = $this->relationLoaded('offers')
            ? $this->offers
            : $this->offers()->get();

        $now = now();

        return $offers
            ->filter(static fn (Offer $offer): bool => (bool) $offer->is_active)
            ->filter(static fn (Offer $offer): bool => $offer->starts_at === null || $offer->starts_at->lessThanOrEqualTo($now))
            ->filter(static fn (Offer $offer): bool => $offer->ends_at === null || $offer->ends_at->greaterThanOrEqualTo($now))
            ->values();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('primary-image')->singleFile();
        $this->addMediaCollection('images');
    }

    public function scopeLowStock($query, mixed $value = true)
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    public function isAvailableNow(): bool
    {
        if ($this->is_available) {
            return true;
        }

        return $this->unavailable_until !== null && now()->greaterThan($this->unavailable_until);
    }

    public function availabilityMode(): string
    {
        if ($this->isAvailableNow()) {
            return 'available';
        }

        if ($this->unavailable_until !== null && now()->lessThanOrEqualTo($this->unavailable_until)) {
            return 'sold_out_today';
        }

        return 'manual_unavailable';
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discounted_price' => 'integer',
            'is_available' => 'boolean',
            'unavailable_until' => 'datetime',
            'availability_note' => 'string',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'preparation_time' => 'integer',
            'is_featured' => 'boolean',
        ];
    }
}
